<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Parser;

use App\Console\Commands\ParserRollupConfidenceCommand;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Models\ParseCorrection;
use App\Models\ParserConfidenceDaily;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parser stats dashboard at /admin/parser.
 *
 * Reads exclusively from `parser_confidence_daily` + `parse_corrections` so
 * the request stays under the 200ms budget. Raw `aggregator_listings.parsed`
 * aggregation belongs to the nightly rollup command — never the request path.
 *
 * Sections:
 *   1. Per-source × per-field median-confidence heat-map (last 30d).
 *   2. Top 10 lowest-confidence (source, field) pairs weighted by log(n+1).
 *   3. Top 10 (source, field) by recent correction count.
 *   4. Last 20 corrections (timeline).
 *
 * Cells link back to /discover with status=new + source filter so the user
 * can drill into actual listings. There is no dedicated drill-in view in
 * this step — see the agent report for rationale.
 */
final readonly class IndexController
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(): Response
    {
        $windowStart = CarbonImmutable::now()->utc()->startOfDay()->subDays(29);

        $sources = AggregatorSource::query()
            ->orderBy('type')
            ->get(['id', 'type', 'name', 'enabled']);

        $rollups = ParserConfidenceDaily::query()
            ->where('day', '>=', $windowStart->toDateString())
            ->get();

        $hasRollupData = $rollups->isNotEmpty();

        $matrix = $this->buildMatrix($rollups, $sources);
        $lowestConfidence = $this->buildLowestConfidence($rollups, $sources);
        $topCorrections = $this->buildTopCorrections($rollups, $sources);
        $recentCorrections = $this->buildRecentCorrections();

        return Inertia::render('Admin/Parser/Index', [
            'has_rollup_data' => $hasRollupData,
            'window_days' => 30,
            'fields' => ParserRollupConfidenceCommand::CANONICAL_FIELDS,
            'sources' => $sources->map(fn (AggregatorSource $s): array => [
                'id' => (int) $s->id,
                'type' => $s->type instanceof AggregatorSourceType ? $s->type->value : (string) $s->type,
                'name' => (string) $s->name,
                'enabled' => (bool) $s->enabled,
            ])->values()->all(),
            'matrix' => $matrix,
            'lowest_confidence' => $lowestConfidence,
            'top_corrections' => $topCorrections,
            'recent_corrections' => $recentCorrections,
            'thresholds' => [
                // Bands tuned against live data (Phase 3 §9, May 2026). The
                // distribution is bimodal: nothing sits between 0.4–0.7, so
                // those endpoints split the tails cleanly.
                'red_below' => 0.40,
                'green_at_or_above' => 0.70,
            ],
        ]);
    }

    /**
     * Aggregate (source_id, field) → {n, weighted_p50, n_corrections}
     * over the 30-day window. Returns indexed cells for the heat-map.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ParserConfidenceDaily>  $rollups
     * @param  \Illuminate\Database\Eloquent\Collection<int, AggregatorSource>  $sources
     * @return array<int, array{
     *     source_id: int,
     *     source_type: string,
     *     field: string,
     *     n: int,
     *     p50: ?float,
     *     n_corrections: int
     * }>
     */
    private function buildMatrix($rollups, $sources): array
    {
        // Weighted p50 over the window: sum(p50_day * n_day) / sum(n_day).
        // Lighter than re-running the percentile query and good enough for
        // a single matrix cell.
        $cells = [];
        foreach ($rollups as $r) {
            $key = $r->source_id.'|'.$r->field;
            if (! isset($cells[$key])) {
                $cells[$key] = [
                    'source_id' => (int) $r->source_id,
                    'field' => (string) $r->field,
                    'n' => 0,
                    'p50_weighted_sum' => 0.0,
                    'n_corrections' => 0,
                ];
            }
            $cells[$key]['n'] += (int) $r->n;
            if ($r->p50 !== null) {
                $cells[$key]['p50_weighted_sum'] += ((float) $r->p50) * (int) $r->n;
            }
            $cells[$key]['n_corrections'] += (int) $r->n_corrections;
        }

        $sourceTypeById = [];
        foreach ($sources as $s) {
            $sourceTypeById[(int) $s->id] = $s->type instanceof AggregatorSourceType
                ? $s->type->value
                : (string) $s->type;
        }

        $out = [];
        foreach ($cells as $cell) {
            $p50 = $cell['n'] > 0 ? round($cell['p50_weighted_sum'] / $cell['n'], 4) : null;
            $out[] = [
                'source_id' => $cell['source_id'],
                'source_type' => $sourceTypeById[$cell['source_id']] ?? '',
                'field' => $cell['field'],
                'n' => $cell['n'],
                'p50' => $p50,
                'n_corrections' => $cell['n_corrections'],
            ];
        }

        return $out;
    }

    /**
     * Top 10 (source, field) pairs ranked by `p50 * log(n + 1)` ascending.
     * Weight by log(n+1) so a 1-row outlier with p50=0 doesn't beat a 100-row
     * pair with p50=0.3.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ParserConfidenceDaily>  $rollups
     * @param  \Illuminate\Database\Eloquent\Collection<int, AggregatorSource>  $sources
     * @return list<array{
     *     source_id: int,
     *     source_type: string,
     *     field: string,
     *     p50: float,
     *     n: int,
     *     score: float
     * }>
     */
    private function buildLowestConfidence($rollups, $sources): array
    {
        $matrix = $this->buildMatrix($rollups, $sources);

        $scored = [];
        foreach ($matrix as $cell) {
            if ($cell['n'] === 0 || $cell['p50'] === null) {
                continue;
            }
            $scored[] = [
                'source_id' => $cell['source_id'],
                'source_type' => $cell['source_type'],
                'field' => $cell['field'],
                'p50' => (float) $cell['p50'],
                'n' => $cell['n'],
                'score' => (float) $cell['p50'] * log($cell['n'] + 1),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return array_slice($scored, 0, 10);
    }

    /**
     * Top 10 (source, field) pairs by correction count over the window.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ParserConfidenceDaily>  $rollups
     * @param  \Illuminate\Database\Eloquent\Collection<int, AggregatorSource>  $sources
     * @return list<array{
     *     source_id: int,
     *     source_type: string,
     *     field: string,
     *     n_corrections: int
     * }>
     */
    private function buildTopCorrections($rollups, $sources): array
    {
        $matrix = $this->buildMatrix($rollups, $sources);

        $filtered = array_values(array_filter(
            $matrix,
            static fn (array $c): bool => $c['n_corrections'] > 0
        ));

        usort(
            $filtered,
            static fn (array $a, array $b): int => $b['n_corrections'] <=> $a['n_corrections']
        );

        return array_map(
            static fn (array $c): array => [
                'source_id' => $c['source_id'],
                'source_type' => $c['source_type'],
                'field' => $c['field'],
                'n_corrections' => $c['n_corrections'],
            ],
            array_slice($filtered, 0, 10),
        );
    }

    /**
     * Last 20 corrections with listing role title for the timeline section.
     *
     * @return list<array{
     *     id: int,
     *     created_at: string,
     *     field: string,
     *     source_type: string,
     *     listing_id: int,
     *     listing_role_title: ?string,
     *     old_value: mixed,
     *     new_value: mixed
     * }>
     */
    private function buildRecentCorrections(): array
    {
        return ParseCorrection::query()
            ->with(['listing:id,source_id,parsed_role_title', 'listing.source:id,type'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (ParseCorrection $c): array {
                $listing = $c->listing;
                $sourceType = $listing?->source?->type;

                return [
                    'id' => (int) $c->id,
                    'created_at' => $c->created_at instanceof CarbonImmutable
                        ? $c->created_at->toIso8601String()
                        : (string) $c->created_at,
                    'field' => (string) $c->field,
                    'source_type' => $sourceType instanceof AggregatorSourceType
                        ? $sourceType->value
                        : (string) ($sourceType ?? ''),
                    'listing_id' => (int) $c->listing_id,
                    'listing_role_title' => $listing?->parsed_role_title,
                    'old_value' => $c->old_value,
                    'new_value' => $c->new_value,
                ];
            })
            ->values()
            ->all();
    }
}
