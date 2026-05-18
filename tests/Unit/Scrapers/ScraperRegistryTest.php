<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers;

use App\Enums\AtsType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\ScraperRunResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScraperRegistryTest extends TestCase
{
    #[Test]
    public function resolves_a_registered_scraper_by_ats_type(): void
    {
        $registry = new ScraperRegistry();
        $registry->register($this->fakeScraper(AtsType::GREENHOUSE));

        $this->assertSame(AtsType::GREENHOUSE, $registry->resolve(AtsType::GREENHOUSE)->name());
    }

    #[Test]
    public function throws_on_unknown_ats_type(): void
    {
        $registry = new ScraperRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->resolve(AtsType::LEVER);
    }

    #[Test]
    public function has_reports_registration_state(): void
    {
        $registry = new ScraperRegistry();
        $this->assertFalse($registry->has(AtsType::ASHBY));

        $registry->register($this->fakeScraper(AtsType::ASHBY));

        $this->assertTrue($registry->has(AtsType::ASHBY));
    }

    #[Test]
    public function registering_the_same_type_twice_replaces_the_prior_binding(): void
    {
        $registry = new ScraperRegistry();
        $first = $this->fakeScraper(AtsType::LEVER, concurrency: 1);
        $second = $this->fakeScraper(AtsType::LEVER, concurrency: 99);

        $registry->register($first);
        $registry->register($second);

        $this->assertSame(99, $registry->resolve(AtsType::LEVER)->concurrency());
        $this->assertCount(1, $registry->all());
    }

    private function fakeScraper(AtsType $type, int $concurrency = 10): AtsScraperInterface
    {
        return new class($type, $concurrency) implements AtsScraperInterface
        {
            public function __construct(private AtsType $type, private int $concurrency) {}

            public function name(): AtsType
            {
                return $this->type;
            }

            public function concurrency(): int
            {
                return $this->concurrency;
            }

            public function run(Company $company): ScraperRunResult
            {
                return new ScraperRunResult(0, 0, 0);
            }
        };
    }
}
