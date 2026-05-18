<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

/**
 * Outcome of a single aggregator scraper run against one source.
 *
 * Drives the row written to `scrape_runs` (with source_kind = 'aggregator',
 * company_id = null) and the structured cron-log output.
 *
 * `closedCount` is intentionally absent: aggregators don't close listings —
 * they only ingest new ones. Retention is handled by the 90-day sweep job.
 */
final readonly class AggregatorRunResult
{
    /**
     * @param  list<array{message: string, context?: array<string, mixed>}>  $errors
     */
    public function __construct(
        public int $fetched,
        public int $newCount,
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
