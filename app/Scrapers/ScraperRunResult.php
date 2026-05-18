<?php

declare(strict_types=1);

namespace App\Scrapers;

/**
 * Outcome of a single scraper run against one company.
 *
 * Drives the row written to `scrape_runs` and the structured cron-log output.
 */
final readonly class ScraperRunResult
{
    /**
     * @param  list<array{message: string, context?: array<string, mixed>}>  $errors
     */
    public function __construct(
        public int $fetched,
        public int $newCount,
        public int $closedCount,
        public array $errors = [],
        public bool $notModified = false,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {}

    public static function notModified(?string $etag = null, ?string $lastModified = null): self
    {
        return new self(
            fetched: 0,
            newCount: 0,
            closedCount: 0,
            notModified: true,
            etag: $etag,
            lastModified: $lastModified,
        );
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
