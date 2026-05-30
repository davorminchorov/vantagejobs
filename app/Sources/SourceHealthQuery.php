<?php

declare(strict_types=1);

namespace App\Sources;

use App\Models\AggregatorSource;
use App\Models\ParserConfidenceDaily;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;

/**
 * Computes a green / yellow / red health status for every enabled aggregator
 * source in the active tenant, reusing the signals the scrape orchestrator
 * already maintains:
 *
 *  - AggregatorSource.consecutive_failures: at or above the configured
 *    failure-alert threshold the source is RED (scrapes are flat-out failing).
 *  - AggregatorSource.last_run_at staleness: enabled but not run within the
 *    stale-run window (or never run) is YELLOW.
 *  - ParserConfidenceDaily p50 week-over-week: a drop in the source's median
 *    parse confidence larger than the configured percentage is RED, a smaller
 *    but material drop (half the threshold) is YELLOW. This catches a source
 *    that still returns 200s but whose markup changed under us.
 *
 * RED outranks YELLOW outranks GREEN. The most severe reason wins, and the
 * full reason list is carried so the dashboard tile and the alert email can
 * explain exactly what tripped.
 *
 * Tenant scoping is implicit: both models use BelongsToTenant, so every read
 * is bounded by the active TenantContext.
 */
final readonly class SourceHealthQuery
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * Per-source health, worst-first, for the active tenant's enabled sources.
     *
     * @return list<array{
     *     source_id: int,
     *     key: string,
     *     name: string,
     *     status: string,
     *     reasons: list<string>,
     *     consecutive_failures: int,
     *     last_run_at: string|null,
     *     last_error: string|null,
     *     confidence_p50: float|null,
     *     confidence_p50_prev: float|null,
     *     confidence_drop_pct: float|null
     * }>
     */
    public function perSource(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $sources = AggregatorSource::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($sources->isEmpty()) {
            return [];
        }

        $confidence = $this->confidenceBySource($sources->pluck('id')->all(), $now);

        $rows = $sources
            ->map(fn (AggregatorSource $source): array => $this->evaluate($source, $confidence, $now))
            ->all();

        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (string $status): int => match ($status) {
                'red' => 0,
                'yellow' => 1,
                default => 2,
            };

            return [$rank($a['status']), $a['name']] <=> [$rank($b['status']), $b['name']];
        });

        return array_values($rows);
    }

    /**
     * Roll the per-source rows up into a single summary for the dashboard tile:
     * the overall (worst) colour plus per-status counts.
     *
     * @return array{
     *     status: string,
     *     counts: array{green: int, yellow: int, red: int},
     *     total: int,
     *     sources: list<array<string, mixed>>
     * }
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $sources = $this->perSource($now);

        $counts = ['green' => 0, 'yellow' => 0, 'red' => 0];
        foreach ($sources as $source) {
            $counts[$source['status']]++;
        }

        $overall = 'green';
        if ($counts['red'] > 0) {
            $overall = 'red';
        } elseif ($counts['yellow'] > 0) {
            $overall = 'yellow';
        }

        return [
            'status' => $overall,
            'counts' => $counts,
            'total' => count($sources),
            'sources' => $sources,
        ];
    }

    /**
     * Evaluate a single source against the failure, staleness, and confidence
     * rules.
     *
     * @param  array<int, array{p50: float|null, p50_prev: float|null}>  $confidence
     * @return array<string, mixed>
     */
    private function evaluate(AggregatorSource $source, array $confidence, CarbonImmutable $now): array
    {
        $failureThreshold = (int) $this->config->get('scrapers.source_health.consecutive_failure_alert', 3);
        $dropPct = (float) $this->config->get('scrapers.source_health.confidence_drop_wow_pct', 20);
        $staleHours = (int) $this->config->get('scrapers.source_health.stale_run_hours', 48);

        $status = 'green';
        $reasons = [];

        $promote = static function (string $candidate) use (&$status): void {
            $rank = static fn (string $s): int => match ($s) {
                'red' => 2,
                'yellow' => 1,
                default => 0,
            };
            if ($rank($candidate) > $rank($status)) {
                $status = $candidate;
            }
        };

        $failures = (int) $source->consecutive_failures;
        if ($failures >= $failureThreshold) {
            $promote('red');
            $reasons[] = sprintf('%d consecutive failed runs', $failures);
        }

        $lastRunAt = $source->last_run_at;
        if ($lastRunAt === null) {
            $promote('yellow');
            $reasons[] = 'never run';
        } elseif (CarbonImmutable::parse($lastRunAt)->lt($now->subHours($staleHours))) {
            $promote('yellow');
            $reasons[] = sprintf('no run in %d+ hours', $staleHours);
        }

        $conf = $confidence[$source->id] ?? ['p50' => null, 'p50_prev' => null];
        $dropActual = $this->dropPct($conf['p50_prev'], $conf['p50']);

        if ($dropActual !== null && $dropActual > $dropPct) {
            $promote('red');
            $reasons[] = sprintf('parse confidence down %.0f%% week-over-week', $dropActual);
        } elseif ($dropActual !== null && $dropActual > $dropPct / 2) {
            $promote('yellow');
            $reasons[] = sprintf('parse confidence down %.0f%% week-over-week', $dropActual);
        }

        if ($reasons === []) {
            $reasons[] = 'healthy';
        }

        return [
            'source_id' => (int) $source->id,
            'key' => $source->type instanceof \BackedEnum ? (string) $source->type->value : (string) $source->getAttribute('type'),
            'name' => (string) $source->name,
            'status' => $status,
            'reasons' => $reasons,
            'consecutive_failures' => $failures,
            'last_run_at' => $lastRunAt?->toIso8601String(),
            'last_error' => $source->last_error,
            'confidence_p50' => $conf['p50'],
            'confidence_p50_prev' => $conf['p50_prev'],
            'confidence_drop_pct' => $dropActual,
        ];
    }

    /**
     * Week-over-week median (p50) parse confidence per source: the average p50
     * across all fields for the current 7-day window vs the prior 7-day window.
     *
     * @param  list<int>  $sourceIds
     * @return array<int, array{p50: float|null, p50_prev: float|null}>
     */
    private function confidenceBySource(array $sourceIds, CarbonImmutable $now): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $thisWeekStart = $now->subDays(7)->toDateString();
        $today = $now->toDateString();
        $prevWeekStart = $now->subDays(14)->toDateString();
        $prevWeekEnd = $now->subDays(7)->toDateString();

        $current = $this->averageP50($sourceIds, $thisWeekStart, $today);
        $previous = $this->averageP50($sourceIds, $prevWeekStart, $prevWeekEnd);

        $out = [];
        foreach ($sourceIds as $id) {
            $out[$id] = [
                'p50' => $current[$id] ?? null,
                'p50_prev' => $previous[$id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Average non-null p50 per source over [from, to).
     *
     * @param  list<int>  $sourceIds
     * @return array<int, float>
     */
    private function averageP50(array $sourceIds, string $from, string $to): array
    {
        /** @var Collection<int, ParserConfidenceDaily> $rows */
        $rows = ParserConfidenceDaily::query()
            ->whereIn('source_id', $sourceIds)
            ->whereNotNull('p50')
            ->where('day', '>=', $from)
            ->where('day', '<', $to)
            ->get(['source_id', 'p50']);

        return $rows
            ->groupBy('source_id')
            ->map(static fn (Collection $group): float => (float) $group->avg('p50'))
            ->all();
    }

    /**
     * Percentage drop from a previous value to a current value, or null when
     * there is no comparable baseline. Positive means confidence fell.
     */
    private function dropPct(?float $previous, ?float $current): ?float
    {
        if ($previous === null || $current === null || $previous <= 0.0) {
            return null;
        }

        $drop = (($previous - $current) / $previous) * 100;

        return $drop > 0 ? round($drop, 2) : 0.0;
    }
}
