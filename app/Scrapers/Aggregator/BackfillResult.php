<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

/**
 * Summary returned by ScrapeBackfillAction per source.
 *
 * Mirrors the JSON shape the scrape:backfill command emits.
 */
final readonly class BackfillResult
{
    /**
     * @param  list<array{message: string, context?: array<string, mixed>}>  $errors
     */
    public function __construct(
        public string $source,
        public int $daysRequested,
        public int $daysProcessed,
        public int $threadsPulled,
        public int $newListings,
        public int $updatedListings,
        public int $outOfRetention,
        public array $errors = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'days_requested' => $this->daysRequested,
            'days_processed' => $this->daysProcessed,
            'threads_pulled' => $this->threadsPulled,
            'new_listings' => $this->newListings,
            'updated_listings' => $this->updatedListings,
            'out_of_retention' => $this->outOfRetention,
            'errors' => $this->errors,
        ];
    }
}
