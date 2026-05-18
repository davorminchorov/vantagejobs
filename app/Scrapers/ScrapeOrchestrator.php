<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Enums\AtsType;
use App\Models\Company;
use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Drives a scraper end-to-end for one company: opens a scrape_runs row,
 * resolves the right scraper, runs it, captures counts + errors, closes
 * the row.
 *
 * Sequential per company today. TODO(post-phase-1): swap to a per-source
 * Saloon pool so each connector batches up to its `concurrency()` cap.
 * Will require splitting each scraper's run() into buildRequest() +
 * handleResponse() — keep that boundary in mind when touching scrapers.
 */
final class ScrapeOrchestrator
{
    public function __construct(private readonly ScraperRegistry $registry) {}

    public function runForCompany(Company $company): ScrapeRun
    {
        $atsType = $this->resolveAtsType($company);

        $run = ScrapeRun::query()->create([
            'company_id' => $company->id,
            'source' => $atsType->value,
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
        ]);

        try {
            $scraper = $this->registry->resolve($atsType);
        } catch (InvalidArgumentException $e) {
            return $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
        }

        try {
            $result = $scraper->run($company);
        } catch (Throwable $e) {
            return $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
        }

        if ($result->notModified) {
            return $this->finalize($run, status: 'not_modified');
        }

        return $this->finalize(
            $run,
            status: $result->hasErrors() ? 'partial' : 'completed',
            fetched: $result->fetched,
            newCount: $result->newCount,
            closedCount: $result->closedCount,
            errors: $result->errors,
        );
    }

    private function resolveAtsType(Company $company): AtsType
    {
        $raw = $company->ats_type;

        if (! is_string($raw) || $raw === '') {
            throw new InvalidArgumentException("Company [{$company->id}] has no ats_type set.");
        }

        $type = AtsType::tryFrom($raw)
            ?? throw new InvalidArgumentException("Company [{$company->id}] has unknown ats_type [{$raw}].");

        if (! is_string($company->ats_slug) || $company->ats_slug === '') {
            throw new InvalidArgumentException("Company [{$company->id}] has no ats_slug set.");
        }

        return $type;
    }

    /**
     * @param  list<array{message: string, context?: array<string, mixed>}>  $errors
     */
    private function finalize(
        ScrapeRun $run,
        string $status,
        int $fetched = 0,
        int $newCount = 0,
        int $closedCount = 0,
        array $errors = [],
    ): ScrapeRun {
        $run->fill([
            'status' => $status,
            'finished_at' => CarbonImmutable::now(),
            'fetched' => $fetched,
            'new_count' => $newCount,
            'closed_count' => $closedCount,
            'errors' => $errors === [] ? null : $errors,
        ])->save();

        return $run->refresh();
    }
}
