<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use InvalidArgumentException;

/**
 * Resolves the concrete aggregator scraper for a given source type.
 *
 * Bound as `scoped` (matching the Phase 1 ScraperRegistry) so Octane resets
 * the map between requests rather than carrying potentially stale closures
 * forward.
 */
final class AggregatorRegistry
{
    /**
     * @var array<string, AggregatorScraperInterface>
     */
    private array $scrapers = [];

    public function register(AggregatorScraperInterface $scraper): void
    {
        $this->scrapers[$scraper->name()->value] = $scraper;
    }

    public function resolve(AggregatorSourceType $type): AggregatorScraperInterface
    {
        return $this->scrapers[$type->value]
            ?? throw new InvalidArgumentException("No aggregator scraper registered for type [{$type->value}].");
    }

    public function has(AggregatorSourceType $type): bool
    {
        return isset($this->scrapers[$type->value]);
    }

    /**
     * @return list<AggregatorScraperInterface>
     */
    public function all(): array
    {
        return array_values($this->scrapers);
    }
}
