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

    /**
     * HTTP statuses that mean the board itself is gone (deleted, moved, made
     * private) rather than temporarily failing. Retrying won't help, so they
     * feed the redetect pipeline instead of the backoff counter.
     */
    public const DEAD_BOARD_HTTP_STATUSES = [401, 403, 404, 410];

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
     * Bookkeeping for the ATS-switch detector (PRD §11.2) and the scrape:all
     * failure backoff.
     *
     * Dead-board responses (HTTP 404/401/403/410, recorded in run errors as
     * context.http_status) increment `ats_consecutive_404s`; once that
     * crosses the threshold, `ats_status` flips to 'needs_redetection' so the
     * `signals:ats:redetect` command picks the company up. The column keeps
     * its historical name but counts all four statuses.
     *
     * Every other failed run (5xx, timeouts, 429, malformed payloads, the
     * mass-close guard) increments `ats_consecutive_failures` instead, which
     * scrape:all uses to back the company off exponentially. A partial run
     * that still fetched postings is not a failure for backoff purposes.
     *
     * Both counters reset to zero on any non-error outcome (completed /
     * not_modified). Never overwrites a 'redetect_failed' status with 'ok'
     * on a single fluke success.
     */
    private function recordAtsHealth(Company $company, ScrapeRun $run): void
    {
        if ($this->runHadDeadBoardStatus($run)) {
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
            if (
                $company->ats_consecutive_404s === 0
                && $company->ats_consecutive_failures === 0
                && $company->ats_status === 'ok'
            ) {
                return;
            }

            $company->ats_consecutive_404s = 0;
            $company->ats_consecutive_failures = 0;
            if ($company->ats_status === 'needs_redetection') {
                $company->ats_status = 'ok';
            }
            $company->save();

            return;
        }

        if ($run->status === 'error' || ($run->status === 'partial' && (int) $run->fetched === 0)) {
            $company->ats_consecutive_failures = (int) $company->ats_consecutive_failures + 1;
            $company->save();
        }
    }

    private function runHadDeadBoardStatus(ScrapeRun $run): bool
    {
        if (! is_array($run->errors)) {
            return false;
        }

        foreach ($run->errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $status = $error['context']['http_status'] ?? null;
            if (is_int($status) && in_array($status, self::DEAD_BOARD_HTTP_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }
}
