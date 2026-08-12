<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AtsType;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Scrapers\ScrapeOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class ScrapeAllCommand extends Command
{
    /**
     * Tier -> hours between attempts. A-tier runs tighter than the PRD §6.2
     * baseline of 6h: being an early applicant at top-priority companies is
     * the whole point of tracking them, and the ATS APIs involved (Greenhouse
     * Job Board, Lever, Ashby) document tolerance for polite frequent polling.
     * `graveyard` never reaches this map: Company::scrapeable() already
     * excludes inactive companies.
     */
    private const array TIER_INTERVAL_HOURS = [
        'A' => 2,
        'B' => 12,
        'C' => 24,
    ];

    protected $signature = 'scrape:all
        {--tenant= : Tenant slug}
        {--source= : Restrict to a single ATS type (e.g. greenhouse, lever, ashby, workable, smartrecruiters, personio, recruitee, teamtailor)}
        {--queue : Dispatch one RunCompanyScrapeJob per company instead of running inline. Isolates failures and reuses the job uniqueness lock; use for the scheduled sweep.}
        {--all : With --queue, dispatch every eligible company regardless of tier cadence. Used for on-demand admin triggers.}';

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

        $query = Company::query()->scrapeable();
        if ($sourceFilter instanceof AtsType) {
            $query->where('ats_type', $sourceFilter->value);
        }

        $companies = $query->orderBy('id')->get();

        if ($this->option('queue')) {
            return $this->dispatchQueued($tenant, $companies, $sourceFilter, $startedAt);
        }

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

    /**
     * @param  Collection<int, Company>  $companies
     */
    private function dispatchQueued(
        Tenant $tenant,
        Collection $companies,
        ?AtsType $sourceFilter,
        CarbonImmutable $startedAt,
    ): int {
        $due = $this->option('all') ? $companies : $this->filterDue($companies, $startedAt);

        foreach ($due as $company) {
            RunCompanyScrapeJob::dispatch((int) $tenant->id, (int) $company->id);
        }

        $this->line((string) json_encode([
            'command' => 'scrape:all',
            'tenant' => $tenant->slug,
            'source' => $sourceFilter?->value,
            'queued' => $due->count(),
            'skipped_not_due' => $companies->count() - $due->count(),
            'started_at' => $startedAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Filters to companies whose tier interval has elapsed since their last
     * ATS scrape attempt.
     *
     * `last_checked_at` is only written on a successful poll (200/304, see
     * each scraper's run()) — a company that has been erroring every sweep
     * would never update it, and would then look permanently "not due" (or
     * worse, always due, depending on the initial value). scrape_runs.started_at
     * records every attempt regardless of outcome, so it is the only column
     * that can drive due-ness safely. A company with no runs yet is always due.
     *
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, Company>
     */
    private function filterDue(Collection $companies, CarbonImmutable $now): Collection
    {
        if ($companies->isEmpty()) {
            return $companies;
        }

        /** @var array<int, string> $lastStartedAtByCompanyId */
        $lastStartedAtByCompanyId = ScrapeRun::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('source_kind', 'ats')
            ->selectRaw('company_id, MAX(started_at) as last_started_at')
            ->groupBy('company_id')
            ->pluck('last_started_at', 'company_id')
            ->all();

        return $companies->filter(function (Company $company) use ($lastStartedAtByCompanyId, $now): bool {
            $lastStartedAt = $lastStartedAtByCompanyId[$company->id] ?? null;
            if (! is_string($lastStartedAt)) {
                return true;
            }

            $hours = self::TIER_INTERVAL_HOURS[$company->tier] ?? self::TIER_INTERVAL_HOURS['C'];

            return CarbonImmutable::parse($lastStartedAt)->addHours($hours)->lessThanOrEqualTo($now);
        })->values();
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
