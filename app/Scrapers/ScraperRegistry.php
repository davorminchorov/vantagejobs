<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Enums\AtsType;
use App\Scrapers\Contracts\AtsScraperInterface;
use InvalidArgumentException;

/**
 * Resolves the concrete scraper for a given ATS type.
 *
 * Bound as `scoped` (not `singleton`) so Octane resets the map between
 * requests rather than carrying potentially stale closures forward.
 */
final class ScraperRegistry
{
    /**
     * @var array<string, AtsScraperInterface>
     */
    private array $scrapers = [];

    public function register(AtsScraperInterface $scraper): void
    {
        $this->scrapers[$scraper->name()->value] = $scraper;
    }

    public function resolve(AtsType $type): AtsScraperInterface
    {
        return $this->scrapers[$type->value]
            ?? throw new InvalidArgumentException("No scraper registered for ATS type [{$type->value}].");
    }

    public function has(AtsType $type): bool
    {
        return isset($this->scrapers[$type->value]);
    }

    /**
     * @return list<AtsScraperInterface>
     */
    public function all(): array
    {
        return array_values($this->scrapers);
    }
}
