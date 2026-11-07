<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorSourceType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Models\AggregatorSource;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Dispatches RunAggregatorScrapeJob for every enabled aggregator source whose
 * next_run_at has elapsed (or was never set). AggregatorOrchestrator already
 * advances next_run_at by cadence_minutes on every run (success and failure
 * alike) — this command is the missing other half: something that actually
 * reads it, so the per-source cadence stored on the row becomes real instead
 * of a second, hand-maintained schedule in routes/console.php.
 *
 * Dispatching through the queued job (rather than calling the orchestrator
 * inline) reuses RunAggregatorScrapeJob's ShouldBeUnique lock, so a run
 * triggered from /admin/runs can't race a scheduled run of the same source.
 *
 * HackerNews goes through this sweep like every other source: a daily run
 * re-reads the latest monthly thread and only inserts comments it hasn't
 * stored yet.
 *
 * Scheduled every 15 minutes, tenant-agnostic (sweeps every tenant with at
 * least one enabled aggregator source). Emits a single JSON line on stdout.
 */
final class AggregatorDispatchDueCommand extends Command
{
    protected $signature = 'aggregator:dispatch-due
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Dispatch RunAggregatorScrapeJob for every enabled aggregator source whose next_run_at is due.';

    public function handle(TenantContext $context): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants === null) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $totalDispatched = 0;

        foreach ($tenants as $tenant) {
            $totalDispatched += $context->withTenant(
                $tenant,
                fn (): int => $this->dispatchDueForTenant($tenant, $now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'aggregator:dispatch-due',
            'tenants_swept' => $tenants->count(),
            'dispatched' => $totalDispatched,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function dispatchDueForTenant(Tenant $tenant, CarbonImmutable $now): int
    {
        $ids = AggregatorSource::query()
            ->where('enabled', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            RunAggregatorScrapeJob::dispatch((int) $tenant->id, (int) $id);
        }

        return $ids->count();
    }

    /**
     * @return Collection<int, Tenant>|null
     */
    private function resolveTenants(): ?Collection
    {
        $slug = $this->option('tenant');

        if (is_string($slug) && $slug !== '') {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                $this->error("Tenant [{$slug}] not found.");

                return null;
            }

            return new Collection([$tenant]);
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
