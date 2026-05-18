<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\HackerNews\HackerNewsScraper;
use App\Scrapers\Aggregator\Himalayas\HimalayasScraper;
use App\Scrapers\Aggregator\Parser\Extractors\ApplyMethodExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\CompanyNameExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\CompensationExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\DisciplineExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\EmploymentTypeExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\LocationExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RegionExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RemoteExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RoleTitleExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\SeniorityExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\StackTagsExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\VisaSponsorshipExtractor;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use App\Scrapers\Aggregator\RemoteOk\RemoteOkScraper;
use App\Scrapers\Aggregator\Remotive\RemotiveScraper;
use App\Scrapers\Aggregator\WeWorkRemotely\WeWorkRemotelyScraper;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the aggregator registry, the shared ParserPipeline (one set of 12
 * extractors used by every aggregator scraper), and the concrete scraper
 * bindings as they're implemented.
 *
 * Scoped (matching ScraperServiceProvider) so Octane resets state between
 * requests rather than holding stale closures forward.
 */
final class AggregatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AggregatorRegistry::class, fn (): AggregatorRegistry => new AggregatorRegistry());

        $this->app->scoped(ParserPipeline::class, fn (): ParserPipeline => new ParserPipeline([
            new CompanyNameExtractor(),
            new RoleTitleExtractor(),
            new DisciplineExtractor(),
            new SeniorityExtractor(),
            new LocationExtractor(),
            new RegionExtractor(),
            new RemoteExtractor(),
            new CompensationExtractor(),
            new StackTagsExtractor(),
            new EmploymentTypeExtractor(),
            new VisaSponsorshipExtractor(),
            new ApplyMethodExtractor(),
        ]));
    }

    public function boot(): void
    {
        $registry = $this->app->make(AggregatorRegistry::class);
        $registry->register($this->app->make(HackerNewsScraper::class));
        $registry->register($this->app->make(RemotiveScraper::class));
        $registry->register($this->app->make(HimalayasScraper::class));
        $registry->register($this->app->make(WeWorkRemotelyScraper::class));
        $registry->register($this->app->make(RemoteOkScraper::class));
    }
}
