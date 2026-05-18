<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Contracts;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorRunResult;

/**
 * Contract every aggregator-source scraper implements.
 *
 * Unlike AtsScraperInterface (one fetch per Company), an aggregator run pulls
 * many companies' postings per fetch, runs them through the ParserPipeline,
 * and persists AggregatorListing rows. Auto-link / dedup / volume-cap passes
 * happen on the persisted rows in later steps.
 *
 * Implementations live under app/Scrapers/Aggregator/{Source}/ and register
 * themselves with AggregatorRegistry in AggregatorServiceProvider.
 */
interface AggregatorScraperInterface
{
    public function name(): AggregatorSourceType;

    /**
     * Per-source concurrency cap. Most aggregators are 1 (strict ToS or
     * polite-poll guidance per SOURCES.md §2.2–2.5).
     */
    public function concurrency(): int;

    /**
     * Fetch, parse, dedup-on-(source_id, external_id), and persist for one
     * source. Implementations update the source's run-state fields via the
     * orchestrator rather than inline.
     */
    public function run(AggregatorSource $source): AggregatorRunResult;
}
