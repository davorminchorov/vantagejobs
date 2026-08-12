<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Enums\AtsType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * POST /admin/runs/trigger — queue scraper runs on demand.
 *
 * scope values:
 *   all                  every ATS-configured company + every enabled aggregator
 *   ats                  every ATS-configured company
 *   ats:{type}           companies on one ATS platform
 *   aggregators          every enabled aggregator source
 *   aggregator:{type}    one aggregator source (runs even if disabled)
 *
 * Queries are tenant-scoped by the global scope, so a trigger can only ever
 * dispatch the current tenant's targets. Each job opens its own scrape_runs
 * row; the page polls those rows for live progress.
 */
final readonly class TriggerController
{
    public function __construct(private TenantContext $context) {}

    public function __invoke(TriggerScrapersRequest $request): RedirectResponse
    {
        $tenantId = (int) $this->context->currentOrFail()->id;
        $scope = $request->scope();

        $dispatched = match (true) {
            $scope === 'all' => $this->dispatchAts($tenantId, null) + $this->dispatchAllAggregators($tenantId),
            $scope === 'ats' => $this->dispatchAts($tenantId, null),
            str_starts_with($scope, 'ats:') => $this->dispatchAts($tenantId, AtsType::from(substr($scope, 4))),
            $scope === 'aggregators' => $this->dispatchAllAggregators($tenantId),
            str_starts_with($scope, 'aggregator:') => $this->dispatchOneAggregator($tenantId, AggregatorSourceType::from(substr($scope, 11))),
            default => 0,
        };

        if ($dispatched === 0) {
            return back()->with('success', 'Nothing to run for that selection.');
        }

        $noun = $dispatched === 1 ? 'run' : 'runs';

        return back()->with('success', "Queued {$dispatched} scraper {$noun}.");
    }

    private function dispatchAts(int $tenantId, ?AtsType $type): int
    {
        $query = Company::query()->scrapeable();

        if ($type instanceof AtsType) {
            $query->where('ats_type', $type->value);
        }

        $ids = $query->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            RunCompanyScrapeJob::dispatch($tenantId, (int) $id);
        }

        return $ids->count();
    }

    private function dispatchAllAggregators(int $tenantId): int
    {
        $ids = AggregatorSource::query()
            ->where('enabled', true)
            ->orderBy('type')
            ->pluck('id');

        foreach ($ids as $id) {
            RunAggregatorScrapeJob::dispatch($tenantId, (int) $id);
        }

        return $ids->count();
    }

    private function dispatchOneAggregator(int $tenantId, AggregatorSourceType $type): int
    {
        $source = AggregatorSource::query()->where('type', $type->value)->first();

        if (! $source instanceof AggregatorSource) {
            return 0;
        }

        RunAggregatorScrapeJob::dispatch($tenantId, (int) $source->id);

        return 1;
    }
}
