<?php

declare(strict_types=1);

namespace App\Scrapers\Contracts;

use App\Enums\AtsType;
use App\Models\Company;
use App\Scrapers\ScraperRunResult;

/**
 * Contract every ATS-per-Company scraper implements.
 *
 * Per SOURCES.md Appendix A. Scrapers MUST be idempotent: running twice
 * against an unchanged source produces zero new Events on the second run.
 *
 * Implementations live under app/Scrapers/{Source}/ and register themselves
 * with ScraperRegistry in a service provider.
 */
interface AtsScraperInterface
{
    public function name(): AtsType;

    /**
     * Per-source concurrency cap used by the Saloon pool in scrape:all.
     */
    public function concurrency(): int;

    /**
     * Fetch, diff against existing roles, and persist changes for one company.
     *
     * Implementations write Role + Event rows and update company etag/last_modified
     * + last_checked_at as part of the run. The returned result is rolled up into
     * the surrounding scrape_runs row by the caller.
     */
    public function run(Company $company): ScraperRunResult;
}
