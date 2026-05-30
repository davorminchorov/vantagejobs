<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AggregatorSourceType;
use App\Enums\AtsType;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScraperRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: the scraper registries are bound as `scoped`. A long-running
 * queue worker (Horizon) flushes scoped instances between jobs, so a registry
 * populated only once at boot becomes empty for every job after the first.
 *
 * forgetScopedInstances() reproduces that between-jobs reset in-process.
 */
final class RegistryScopeResetTest extends TestCase
{
    #[Test]
    public function ats_registry_is_still_populated_after_a_scope_reset(): void
    {
        $this->app->make(ScraperRegistry::class)->resolve(AtsType::GREENHOUSE);

        // Simulate the queue worker resetting scoped instances between jobs.
        $this->app->forgetScopedInstances();

        $scraper = $this->app->make(ScraperRegistry::class)->resolve(AtsType::GREENHOUSE);

        $this->assertInstanceOf(AtsScraperInterface::class, $scraper);
    }

    #[Test]
    public function aggregator_registry_is_still_populated_after_a_scope_reset(): void
    {
        $this->app->make(AggregatorRegistry::class)->resolve(AggregatorSourceType::REMOTIVE);

        $this->app->forgetScopedInstances();

        $scraper = $this->app->make(AggregatorRegistry::class)->resolve(AggregatorSourceType::REMOTIVE);

        $this->assertInstanceOf(AggregatorScraperInterface::class, $scraper);
    }
}
