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
 * Called once per company from inside RunCompanyScrapeJob. Parallelism is
 * job-level, not connector-level: scrape:all --queue dispatches one queued
 * job per company, and Horizon's worker pool runs those jobs concurrently.
 * There is no per-source request pool here — each job makes its own single
 * HTTP call sequentially inside run().
 */
final class ScrapeOrchestrator
{
    public function __construct(private readonly ScraperRegistry $registry) {}

    /**
     * Threshold after which a company's existing ATS scrape is presumed dead and
     * the redetect sweep kicks in (PRD §11.2).
     */
    public const ATS_REDETECT_404_THRESHOLD = 7;

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
            $finalRun = $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
            $this->recordAtsHealth($company, $finalRun);

            return $finalRun;
        }

        try {
            $result = $scraper->run($company);
        } catch (Throwable $e) {
            $finalRun = $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
            $this->recordAtsHealth($company, $finalRun);

            return $finalRun;
        }

        if ($result->notModified) {
            $finalRun = $this->finalize($run, status: 'not_modified');
            $this->recordAtsHealth($company, $finalRun);

            return $finalRun;
        }

        $finalRun = $this->finalize(
            $run,
            status: $result->hasErrors() ? 'partial' : 'completed',
            fetched: $result->fetched,
            newCount: $result->newCount,
            closedCount: $result->closedCount,
            errors: $result->errors,
        );

        $this->recordAtsHealth($company, $finalRun);

        return $finalRun;
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

    /**
     * Bookkeeping for the ATS-switch detector (PRD §11.2).
     *
     * Increment `ats_consecutive_404s` when the underlying transport reported
     * a 404 (recorded in run errors as context.http_status). Reset to zero on
     * any non-error outcome (completed / not_modified). Once the counter
     * crosses the threshold, flip `ats_status` to 'needs_redetection' so the
     * `signals:ats:redetect` command picks the company up.
     *
     * Only touches the columns it owns — never overwrites a 'redetect_failed'
     * status with 'ok' on a single fluke success.
     */
    private function recordAtsHealth(Company $company, ScrapeRun $run): void
    {
        $is404 = $this->runHadFourOhFour($run);

        if ($is404) {
            $company->ats_consecutive_404s = (int) $company->ats_consecutive_404s + 1;

            if (
                $company->ats_consecutive_404s >= self::ATS_REDETECT_404_THRESHOLD
                && $company->ats_status === 'ok'
            ) {
                $company->ats_status = 'needs_redetection';
            }

            $company->save();

            return;
        }

        if (in_array($run->status, ['completed', 'not_modified'], true)) {
            if ($company->ats_consecutive_404s === 0 && $company->ats_status === 'ok') {
                return;
            }

            $company->ats_consecutive_404s = 0;
            if ($company->ats_status === 'needs_redetection') {
                $company->ats_status = 'ok';
            }
            $company->save();
        }
    }

    private function runHadFourOhFour(ScrapeRun $run): bool
    {
        if (! is_array($run->errors)) {
            return false;
        }

        foreach ($run->errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $status = $error['context']['http_status'] ?? null;
            if (is_int($status) && $status === 404) {
                return true;
            }
        }

        return false;
    }
}
