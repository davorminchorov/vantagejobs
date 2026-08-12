<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues a single aggregator source scrape. Mirrors RunCompanyScrapeJob: sets
 * tenant context, then hands off to the aggregator orchestrator, which owns the
 * scrape_runs row and the source's run-state bookkeeping.
 */
final class RunAggregatorScrapeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    /**
     * Multi-page aggregator fetches can take longer than Horizon's default
     * 60s job timeout. Keep below queue.php's redis `retry_after` to avoid a
     * second worker picking the job up while the first is still running.
     */
    public int $timeout = 120;

    public function __construct(
        public int $tenantId,
        public int $sourceId,
    ) {}

    public function handle(TenantContext $context, AggregatorOrchestrator $orchestrator): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        $context->setCurrent($tenant);

        $source = AggregatorSource::query()->find($this->sourceId);
        if (! $source instanceof AggregatorSource) {
            return;
        }

        $orchestrator->runForSource($source);
    }

    public function uniqueId(): string
    {
        return "run-aggregator-scrape:{$this->tenantId}:{$this->sourceId}";
    }
}
