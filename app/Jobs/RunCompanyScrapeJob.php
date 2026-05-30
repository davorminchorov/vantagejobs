<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Scrapers\ScrapeOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues a single company's ATS scrape. Re-establishes tenant context before
 * any DB access (workers are long-lived and start with no context), then hands
 * off to the orchestrator, which opens and finalizes the scrape_runs row.
 *
 * ShouldBeUnique keeps a second click for the same company from double-running
 * while the first is still in flight.
 */
final class RunCompanyScrapeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(
        public int $tenantId,
        public int $companyId,
    ) {}

    public function handle(TenantContext $context, ScrapeOrchestrator $orchestrator): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        $context->setCurrent($tenant);

        $company = Company::query()->find($this->companyId);
        if (! $company instanceof Company) {
            return;
        }

        $orchestrator->runForCompany($company);
    }

    public function uniqueId(): string
    {
        return "run-company-scrape:{$this->tenantId}:{$this->companyId}";
    }
}
