<?php

declare(strict_types=1);

namespace App\Sources;

use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\ParserConfidenceDaily;
use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;

/**
 * Computes a single green / yellow / red health verdict for the active
 * tenant, so /dashboard and /admin/runs never disagree about whether
 * anything needs attention. Two signal groups feed it:
 *
 *  Aggregator sources (perSource(), unchanged):
 *  - AggregatorSource.consecutive_failures: at or above the configured
 *    failure-alert threshold the source is RED (scrapes are flat-out failing).
 *  - AggregatorSource.last_run_at staleness: enabled but not run within the
 *    stale-run window (or never run) is YELLOW.
 *  - ParserConfidenceDaily p50 week-over-week: a drop in the source's median
 *    parse confidence larger than the configured percentage is RED, a smaller
 *    but material drop (half the threshold) is YELLOW. This catches a source
 *    that still returns 200s but whose markup changed under us.
 *
 *  ATS companies (summary() only, mirroring Admin\Runs\IndexController::sourceHealth()):
 *  - A company's most recent ATS scrape in the last 24h ended in `error`
 *    (RED) or `partial` (YELLOW).
 *  - A company flagged by the ATS-switch detector: `ats_status` of
 *    `redetect_failed` (RED, manual review) or `needs_redetection` (YELLOW,
 *    awaiting automated redetection).
 *
 * RED outranks YELLOW outranks GREEN. The most severe reason wins, and the
 * full reason list is carried so the dashboard tile and the alert email can
 * explain exactly what tripped. Only degraded ATS companies become entries —
 * there is no "healthy ATS company" row, since every enabled aggregator
 * source already gets one from perSource().
 *
 * Tenant scoping is implicit: every model here uses BelongsToTenant, so
 * every read is bounded by the active TenantContext.
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
     * Roll the per-source rows up into a single summary for the dashboard
     * tile and the /admin/runs strip: the overall (worst) colour plus
     * per-status counts, over the union of aggregator sources (all of them)
     * and degraded ATS companies (only the ones with something wrong).
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
        $now ??= CarbonImmutable::now();

        $aggregatorRows = array_map(
            static fn (array $row): array => [
                'kind' => 'aggregator',
                'value' => sprintf('aggregator:%s', $row['key']),
                'source_id' => $row['source_id'],
                'company_id' => null,
                'key' => $row['key'],
                'name' => $row['name'],
                'status' => $row['status'],
                'reasons' => $row['reasons'],
                'consecutive_failures' => $row['consecutive_failures'],
                'last_run_at' => $row['last_run_at'],
                'last_error' => $row['last_error'],
                'confidence_p50' => $row['confidence_p50'],
                'confidence_p50_prev' => $row['confidence_p50_prev'],
                'confidence_drop_pct' => $row['confidence_drop_pct'],
            ],
            $this->perSource($now),
        );

        $sources = [
            ...$aggregatorRows,
            ...$this->atsRunFailureRows($now),
            ...$this->atsSwitchFlagRows(),
        ];

        usort($sources, static function (array $a, array $b): int {
            $rank = static fn (string $status): int => match ($status) {
                'red' => 0,
                'yellow' => 1,
                default => 2,
            };

            return [$rank($a['status']), $a['name']] <=> [$rank($b['status']), $b['name']];
        });

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
     * Companies whose most recent ATS scrape in the last 24h ended in
     * `error` (red) or `partial` (yellow). Mirrors
     * Admin\Runs\IndexController::sourceHealth() lines 304-360, including the
     * reason text, so both screens describe the same failure identically.
     *
     * @return list<array<string, mixed>>
     */
    private function atsRunFailureRows(CarbonImmutable $now): array
    {
        $since = $now->subDay();

        $recentRuns = ScrapeRun::query()
            ->where('source_kind', 'ats')
            ->whereNotNull('company_id')
            ->where('started_at', '>=', $since)
            ->orderByDesc('started_at')
            ->get(['company_id', 'status', 'started_at', 'errors']);

        if ($recentRuns->isEmpty()) {
            return [];
        }

        $latestByCompany = [];
        foreach ($recentRuns as $run) {
            $companyId = (int) $run->company_id;
            if (! isset($latestByCompany[$companyId])) {
                $latestByCompany[$companyId] = $run;
            }
        }

        $failing = array_filter(
            $latestByCompany,
            static fn (ScrapeRun $run): bool => in_array($run->status, ['error', 'partial'], true),
        );

        if ($failing === []) {
            return [];
        }

        $companies = Company::query()
            ->whereIn('id', array_keys($failing))
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($failing as $companyId => $run) {
            $status = (string) $run->status;
            $severity = $status === 'error' ? 'red' : 'yellow';

            $reason = sprintf('Last ATS run %s', $status);
            if (is_array($run->errors) && $run->errors !== []) {
                $first = $run->errors[0]['message'] ?? null;
                if (is_string($first) && $first !== '') {
                    $reason .= ': '.$first;
                }
            }

            $rows[] = [
                'kind' => 'ats',
                'value' => sprintf('ats:%d', $companyId),
                'source_id' => null,
                'company_id' => $companyId,
                'key' => null,
                'name' => (string) ($companies->get($companyId)?->name ?? ('Company #'.$companyId)),
                'status' => $severity,
                'reasons' => [$reason],
                'consecutive_failures' => 0,
                'last_run_at' => $run->started_at?->toIso8601String(),
                'last_error' => $reason,
                'confidence_p50' => null,
                'confidence_p50_prev' => null,
                'confidence_drop_pct' => null,
            ];
        }

        return $rows;
    }

    /**
     * Companies flagged by the ATS-switch detector (PRD §11.2): `ats_status`
     * of `redetect_failed` (red) or `needs_redetection` (yellow). Mirrors
     * Admin\Runs\IndexController::sourceHealth() lines 362-389, including the
     * reason text.
     *
     * @return list<array<string, mixed>>
     */
    private function atsSwitchFlagRows(): array
    {
        $companies = Company::query()
            ->whereIn('ats_status', ['needs_redetection', 'redetect_failed'])
            ->get(['id', 'name', 'ats_status', 'ats_consecutive_404s', 'ats_redetect_attempts', 'last_checked_at']);

        return $companies
            ->map(static function (Company $company): array {
                $status = (string) $company->ats_status;
                $severity = $status === 'redetect_failed' ? 'red' : 'yellow';

                $reason = match ($status) {
                    'redetect_failed' => sprintf(
                        'ATS redetection failed after %d attempts — manual review required',
                        (int) $company->ats_redetect_attempts,
                    ),
                    'needs_redetection' => sprintf(
                        '%d consecutive 404s — awaiting ATS redetection',
                        (int) $company->ats_consecutive_404s,
                    ),
                    default => 'ATS health alert',
                };

                return [
                    'kind' => 'ats',
                    'value' => sprintf('ats:%d', (int) $company->id),
                    'source_id' => null,
                    'company_id' => (int) $company->id,
                    'key' => null,
                    'name' => (string) $company->name,
                    'status' => $severity,
                    'reasons' => [$reason],
                    'consecutive_failures' => (int) $company->ats_consecutive_404s,
                    'last_run_at' => $company->last_checked_at?->toIso8601String(),
                    'last_error' => null,
                    'confidence_p50' => null,
                    'confidence_p50_prev' => null,
                    'confidence_drop_pct' => null,
                ];
            })
            ->all();
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
