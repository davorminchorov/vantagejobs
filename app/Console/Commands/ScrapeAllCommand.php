<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AtsType;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Scrapers\ScrapeOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ScrapeAllCommand extends Command
{
    protected $signature = 'scrape:all
        {--tenant= : Tenant slug}
        {--source= : Restrict to a single ATS type (e.g. greenhouse, lever, ashby, workable, smartrecruiters, personio, recruitee, teamtailor)}';

    protected $description = 'Run ATS scrapers across every eligible company for a tenant. Cron-friendly: JSON summary on stdout.';

    public function handle(TenantContext $context, ScrapeOrchestrator $orchestrator): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $sourceFilter = $this->resolveSourceFilter();
        if ($sourceFilter === false) {
            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now();

        $query = Company::query()->whereNotNull('ats_type')->whereNotNull('ats_slug');
        if ($sourceFilter instanceof AtsType) {
            $query->where('ats_type', $sourceFilter->value);
        }

        $companies = $query->orderBy('id')->get();

        $runs = [];
        foreach ($companies as $company) {
            $run = $orchestrator->runForCompany($company);
            $runs[] = $this->describe($company, $run);
        }

        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'scrape:all',
            'tenant' => $tenant->slug,
            'source' => $sourceFilter?->value,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
            'totals' => $this->totals($runs),
            'runs' => $runs,
        ], JSON_THROW_ON_ERROR));

        return $this->anyErrors($runs) ? self::FAILURE : self::SUCCESS;
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

    private function resolveSourceFilter(): AtsType|false|null
    {
        $raw = $this->option('source');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $type = AtsType::tryFrom($raw);
        if (! $type instanceof AtsType) {
            $known = implode(', ', array_map(static fn (AtsType $t): string => $t->value, AtsType::cases()));
            $this->error("Unknown --source [{$raw}]. Expected one of: {$known}.");

            return false;
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Company $company, ScrapeRun $run): array
    {
        return [
            'company_id' => $company->id,
            'company' => $company->name,
            'source' => $run->source,
            'status' => $run->status,
            'fetched' => $run->fetched,
            'new' => $run->new_count,
            'closed' => $run->closed_count,
            'errors' => $run->errors ?? [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, int>
     */
    private function totals(array $runs): array
    {
        $totals = [
            'companies' => count($runs),
            'completed' => 0,
            'not_modified' => 0,
            'partial' => 0,
            'errors' => 0,
            'new_roles' => 0,
            'closed_roles' => 0,
        ];

        foreach ($runs as $run) {
            match ($run['status']) {
                'completed' => $totals['completed']++,
                'not_modified' => $totals['not_modified']++,
                'partial' => $totals['partial']++,
                'error' => $totals['errors']++,
                default => null,
            };
            $totals['new_roles'] += (int) $run['new'];
            $totals['closed_roles'] += (int) $run['closed'];
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function anyErrors(array $runs): bool
    {
        foreach ($runs as $run) {
            if ($run['status'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
