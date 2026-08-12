<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Enums\AtsType;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /admin/runs — scrape run history viewer.
 *
 * Paginated, server-filtered list of scrape_runs rows for the current tenant
 * across both ATS scrapes (Phase 1) and aggregator scrapes (Phase 2). Filters
 * are URL-state (plain query string) and defensively parsed so an unknown
 * key never blows up the page.
 *
 * Also surfaces a "source health" strip listing any source whose most recent
 * run failed or whose consecutive_failures counter is at or above the
 * scrapers.source_health.consecutive_failure_alert threshold — per PRD §7.8:
 * "When a source is down, I should see it within one click of /admin/runs."
 */
final readonly class IndexController
{
    public function __invoke(Request $request): Response
    {
        $filters = ScrapeRunListFilters::fromRequest($request);

        $query = ScrapeRun::query()->with([]);
        $filters->applyTo($query);

        $runs = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $runRows = collect($runs->items());
        $runRowsAll = $runRows->all();

        $companyIds = $runRows
            ->where('source_kind', 'ats')
            ->pluck('company_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $companyLookup = $companyIds === []
            ? collect()
            : Company::query()
                ->whereIn('id', $companyIds)
                ->get(['id', 'name'])
                ->keyBy('id');

        $sourceLookup = AggregatorSource::query()
            ->get(['id', 'type', 'name'])
            ->keyBy(fn (AggregatorSource $s): string => $s->type->value);

        $items = array_map(static function (ScrapeRun $run) use ($companyLookup, $sourceLookup): array {
            $duration = null;
            if ($run->started_at !== null && $run->finished_at !== null) {
                $duration = (int) $run->finished_at->diffInSeconds($run->started_at, true);
            }

            $label = $run->source;
            if ($run->source_kind === 'ats' && $run->company_id !== null) {
                $company = $companyLookup->get($run->company_id);
                if ($company !== null) {
                    $label = (string) $company->name;
                }
            } elseif ($run->source_kind === 'aggregator') {
                $source = $sourceLookup->get($run->source);
                if ($source !== null) {
                    $label = (string) $source->name;
                }
            }

            $errorCount = 0;
            if (is_array($run->errors)) {
                $errorCount = count($run->errors);
            }

            return [
                'id' => (int) $run->id,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'duration_seconds' => $duration,
                'source_kind' => (string) $run->source_kind,
                'source' => (string) $run->source,
                'source_label' => $label,
                'company_id' => $run->company_id === null ? null : (int) $run->company_id,
                'status' => (string) $run->status,
                'fetched' => (int) $run->fetched,
                'new_count' => (int) $run->new_count,
                'error_count' => $errorCount,
            ];
        }, $runRowsAll);

        return Inertia::render('Admin/Runs/Index', [
            'runs' => [
                'data' => $items,
                'meta' => [
                    'current_page' => $runs->currentPage(),
                    'last_page' => $runs->lastPage(),
                    'per_page' => $runs->perPage(),
                    'total' => $runs->total(),
                ],
                'links' => [
                    'prev' => $runs->previousPageUrl(),
                    'next' => $runs->nextPageUrl(),
                ],
            ],
            'filters' => $filters->toArray(),
            'options' => [
                'source_kinds' => ScrapeRunListFilters::VALID_SOURCE_KINDS,
                'statuses' => ScrapeRunListFilters::VALID_STATUSES,
                'sources' => $this->sourceOptions(),
            ],
            'health' => $this->sourceHealth(),
            'triggerOptions' => $this->triggerOptions(),
            'activeRunCount' => $this->activeRunCount(),
        ]);
    }

    /**
     * Targets available for manual triggering: ATS types that have at least one
     * configured company (with a count), plus every aggregator source.
     *
     * @return array{
     *     ats_types: list<array{value: string, label: string, count: int}>,
     *     aggregators: list<array{value: string, label: string}>
     * }
     */
    private function triggerOptions(): array
    {
        $atsCounts = Company::query()
            ->whereNotNull('ats_type')
            ->whereNotNull('ats_slug')
            ->selectRaw('ats_type, COUNT(*) AS company_count')
            ->groupBy('ats_type')
            ->pluck('company_count', 'ats_type');

        $atsTypes = [];
        foreach (AtsType::cases() as $type) {
            $count = (int) ($atsCounts[$type->value] ?? 0);
            if ($count === 0) {
                continue;
            }
            $atsTypes[] = [
                'value' => $type->value,
                'label' => ucfirst($type->value),
                'count' => $count,
            ];
        }

        $aggregators = AggregatorSource::query()
            ->orderBy('name')
            ->get(['type', 'name'])
            ->map(static fn (AggregatorSource $s): array => [
                'value' => $s->type->value,
                'label' => (string) $s->name,
            ])
            ->all();

        return [
            'ats_types' => $atsTypes,
            'aggregators' => $aggregators,
        ];
    }

    /**
     * Count of scrape_runs currently in flight (status=running) and started
     * within the last hour, for the current tenant. Drives the live-poll
     * indicator on the page.
     */
    private function activeRunCount(): int
    {
        return ScrapeRun::query()
            ->where('status', 'running')
            ->where('started_at', '>=', CarbonImmutable::now()->subHour())
            ->count();
    }

    /**
     * Distinct sources observed in the last 30 days, plus all enabled
     * aggregator sources, deduplicated on (source_kind, source_id) and
     * labelled "[kind] Name" for the multi-select dropdown.
     *
     * @return list<array{value: string, label: string}>
     */
    private function sourceOptions(): array
    {
        $since = CarbonImmutable::now()->subDays(30);

        $recent = ScrapeRun::query()
            ->select(['source_kind', 'source', 'company_id'])
            ->where('started_at', '>=', $since)
            ->groupBy('source_kind', 'source', 'company_id')
            ->get();

        $companyIds = $recent
            ->where('source_kind', 'ats')
            ->pluck('company_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $companies = $companyIds === []
            ? collect()
            : Company::query()
                ->whereIn('id', $companyIds)
                ->get(['id', 'name'])
                ->keyBy('id');

        $aggregatorSources = AggregatorSource::query()
            ->get(['type', 'name'])
            ->keyBy(fn (AggregatorSource $s): string => $s->type->value);

        $seen = [];
        $options = [];

        foreach ($recent as $row) {
            $kind = (string) $row->source_kind;

            if ($kind === 'ats') {
                if ($row->company_id === null) {
                    continue;
                }
                $value = sprintf('ats:%d', (int) $row->company_id);
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $company = $companies->get((int) $row->company_id);
                $label = sprintf('[ats] %s', $company?->name ?? 'Company #'.$row->company_id);
                $options[] = ['value' => $value, 'label' => $label];
            } else {
                $value = sprintf('aggregator:%s', (string) $row->source);
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $name = $aggregatorSources->get((string) $row->source)?->name
                    ?? (AggregatorSourceType::tryFrom((string) $row->source)?->displayName() ?? (string) $row->source);
                $options[] = ['value' => $value, 'label' => sprintf('[aggregator] %s', $name)];
            }
        }

        // Make sure every enabled aggregator source appears even if it hasn't
        // run in 30 days (e.g., HackerNews's monthly cadence).
        foreach ($aggregatorSources as $type => $source) {
            $value = sprintf('aggregator:%s', $type);
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $options[] = ['value' => $value, 'label' => sprintf('[aggregator] %s', $source->name)];
        }

        usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * @return list<array{
     *     kind: string,
     *     value: string,
     *     label: string,
     *     reason: string,
     *     last_run_at: ?string,
     *     consecutive_failures: int
     * }>
     */
    private function sourceHealth(): array
    {
        $health = [];

        // Same alert threshold SourceHealthQuery uses so the dashboard tile
        // and this strip always agree on which sources count as failing.
        $failureThreshold = max(1, (int) config('scrapers.source_health.consecutive_failure_alert', 3));

        $sources = AggregatorSource::query()
            ->where('consecutive_failures', '>=', $failureThreshold)
            ->get();

        foreach ($sources as $source) {
            $health[] = [
                'kind' => 'aggregator',
                'value' => sprintf('aggregator:%s', $source->type->value),
                'label' => (string) $source->name,
                'reason' => $source->last_error !== null
                    ? sprintf('%d consecutive failures: %s', (int) $source->consecutive_failures, (string) $source->last_error)
                    : sprintf('%d consecutive failures', (int) $source->consecutive_failures),
                'last_run_at' => $source->last_run_at?->toIso8601String(),
                'consecutive_failures' => (int) $source->consecutive_failures,
            ];
        }

        // Companies whose most recent ATS scrape in the last 24h failed.
        $since = CarbonImmutable::now()->subDay();

        $failedAts = ScrapeRun::query()
            ->select(['company_id'])
            ->selectRaw('MAX(started_at) AS latest_started_at')
            ->where('source_kind', 'ats')
            ->whereNotNull('company_id')
            ->where('started_at', '>=', $since)
            ->groupBy('company_id')
            ->get();

        if ($failedAts->isNotEmpty()) {
            $companyIds = $failedAts->pluck('company_id')->all();
            $latestRuns = ScrapeRun::query()
                ->whereIn('company_id', $companyIds)
                ->where('source_kind', 'ats')
                ->where('started_at', '>=', $since)
                ->orderByDesc('started_at')
                ->get(['id', 'company_id', 'status', 'started_at', 'errors']);

            $byCompany = [];
            foreach ($latestRuns as $run) {
                $cid = (int) $run->company_id;
                if (! isset($byCompany[$cid])) {
                    $byCompany[$cid] = $run;
                }
            }

            $companies = Company::query()
                ->whereIn('id', array_keys($byCompany))
                ->get(['id', 'name'])
                ->keyBy('id');

            foreach ($byCompany as $companyId => $run) {
                if (! in_array($run->status, ['error', 'partial'], true)) {
                    continue;
                }

                $reason = sprintf('Last ATS run %s', (string) $run->status);
                if (is_array($run->errors) && $run->errors !== []) {
                    $first = $run->errors[0]['message'] ?? null;
                    if (is_string($first) && $first !== '') {
                        $reason .= ': '.$first;
                    }
                }

                $health[] = [
                    'kind' => 'ats',
                    'value' => sprintf('ats:%d', $companyId),
                    'label' => (string) ($companies->get($companyId)?->name ?? ('Company #'.$companyId)),
                    'reason' => $reason,
                    'last_run_at' => $run->started_at?->toIso8601String(),
                    'consecutive_failures' => 0,
                ];
            }
        }

        // Companies flagged by the ATS-switch detector (PRD §11.2).
        $atsHealthCompanies = Company::query()
            ->whereIn('ats_status', ['needs_redetection', 'redetect_failed'])
            ->get(['id', 'name', 'ats_status', 'ats_consecutive_404s', 'ats_redetect_attempts', 'last_checked_at']);

        foreach ($atsHealthCompanies as $company) {
            $status = (string) $company->ats_status;
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

            $health[] = [
                'kind' => 'ats',
                'value' => sprintf('ats:%d', (int) $company->id),
                'label' => (string) $company->name,
                'reason' => $reason,
                'last_run_at' => $company->last_checked_at?->toIso8601String(),
                'consecutive_failures' => (int) $company->ats_consecutive_404s,
            ];
        }

        return $health;
    }
}
