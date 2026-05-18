<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Scrapers\Ashby\AshbyScraper;
use App\Scrapers\Greenhouse\GreenhouseScraper;
use App\Scrapers\Lever\LeverScraper;
use App\Scrapers\ScraperRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScraperRegistryBindingTest extends TestCase
{
    #[Test]
    public function registry_is_bound_as_scoped(): void
    {
        $first = $this->app->make(ScraperRegistry::class);
        $second = $this->app->make(ScraperRegistry::class);

        $this->assertSame($first, $second, 'Same request should resolve the same registry instance.');
    }

    #[Test]
    public function scrapers_config_is_merged(): void
    {
        $this->assertSame(10, config('scrapers.sources.greenhouse.concurrency'));
        $this->assertSame(10, config('scrapers.sources.lever.concurrency'));
        $this->assertSame(10, config('scrapers.sources.ashby.concurrency'));
        $this->assertIsString(config('scrapers.user_agent'));
    }

    #[Test]
    public function all_three_ats_scrapers_are_registered(): void
    {
        $registry = $this->app->make(ScraperRegistry::class);

        $this->assertInstanceOf(GreenhouseScraper::class, $registry->resolve(AtsType::GREENHOUSE));
        $this->assertInstanceOf(LeverScraper::class, $registry->resolve(AtsType::LEVER));
        $this->assertInstanceOf(AshbyScraper::class, $registry->resolve(AtsType::ASHBY));
    }
}
