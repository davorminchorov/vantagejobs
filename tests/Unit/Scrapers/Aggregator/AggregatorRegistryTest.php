<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AggregatorRegistryTest extends TestCase
{
    #[Test]
    public function resolves_a_registered_scraper_by_source_type(): void
    {
        $registry = new AggregatorRegistry();
        $registry->register($this->fakeScraper(AggregatorSourceType::HN));

        $this->assertSame(AggregatorSourceType::HN, $registry->resolve(AggregatorSourceType::HN)->name());
    }

    #[Test]
    public function throws_on_unknown_source_type(): void
    {
        $registry = new AggregatorRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->resolve(AggregatorSourceType::REMOTIVE);
    }

    #[Test]
    public function has_reports_registration_state(): void
    {
        $registry = new AggregatorRegistry();
        $this->assertFalse($registry->has(AggregatorSourceType::WWR));

        $registry->register($this->fakeScraper(AggregatorSourceType::WWR));

        $this->assertTrue($registry->has(AggregatorSourceType::WWR));
    }

    #[Test]
    public function registering_the_same_type_twice_replaces_the_prior_binding(): void
    {
        $registry = new AggregatorRegistry();
        $first = $this->fakeScraper(AggregatorSourceType::REMOTEOK, concurrency: 1);
        $second = $this->fakeScraper(AggregatorSourceType::REMOTEOK, concurrency: 5);

        $registry->register($first);
        $registry->register($second);

        $this->assertSame(5, $registry->resolve(AggregatorSourceType::REMOTEOK)->concurrency());
        $this->assertCount(1, $registry->all());
    }

    private function fakeScraper(AggregatorSourceType $type, int $concurrency = 1): AggregatorScraperInterface
    {
        return new class($type, $concurrency) implements AggregatorScraperInterface
        {
            public function __construct(private AggregatorSourceType $type, private int $concurrency) {}

            public function name(): AggregatorSourceType
            {
                return $this->type;
            }

            public function concurrency(): int
            {
                return $this->concurrency;
            }

            public function run(AggregatorSource $source): AggregatorRunResult
            {
                return new AggregatorRunResult(0, 0);
            }
        };
    }
}
