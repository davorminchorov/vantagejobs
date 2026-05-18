<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Scrapers\ScrapeOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class ScrapeCompanyCommand extends Command
{
    protected $signature = 'scrape:company {company : Company id} {--tenant= : Tenant slug}';

    protected $description = 'Run the ATS scraper for one company. Writes a scrape_runs row and prints a JSON summary.';

    public function handle(TenantContext $context, ScrapeOrchestrator $orchestrator): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $companyId = (int) $this->argument('company');

        $company = Company::query()->find($companyId);
        if (! $company instanceof Company) {
            $this->error("Company [{$companyId}] not found for tenant [{$tenant->slug}].");

            return self::FAILURE;
        }

        $run = $orchestrator->runForCompany($company);

        $this->line((string) json_encode([
            'command' => 'scrape:company',
            'tenant' => $tenant->slug,
            'company_id' => $company->id,
            'company' => $company->name,
            'source' => $run->source,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'fetched' => $run->fetched,
            'new' => $run->new_count,
            'closed' => $run->closed_count,
            'errors' => $run->errors ?? [],
        ], JSON_THROW_ON_ERROR));

        return $run->status === 'error' ? self::FAILURE : self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');
        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] not found.");

            return null;
        }

        return $tenant;
    }
}
