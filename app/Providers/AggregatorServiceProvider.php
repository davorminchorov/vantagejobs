<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scrapers\Aggregator\Adzuna\AdzunaScraper;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\Artifacts\ScrapeArtifactContext;
use App\Scrapers\Aggregator\FourDayWeek\FourDayWeekScraper;
use App\Scrapers\Aggregator\HackerNews\HackerNewsScraper;
use App\Scrapers\Aggregator\SaveFixtureAction;
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
use App\Scrapers\Aggregator\WorkingNomads\WorkingNomadsScraper;
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

        $this->app->scoped(SaveFixtureAction::class, fn ($app): SaveFixtureAction => new SaveFixtureAction(
            $app->make(\App\Scrapers\Aggregator\SourceContextFactory::class),
            base_path('tests/Fixtures/parsers'),
        ));

        // Carries the in-flight scrape_runs.id + tenant/source/company context
        // into the Saloon response middleware. Scoped so Octane resets it
        // between requests instead of leaking state across runs.
        $this->app->scoped(ScrapeArtifactContext::class, fn (): ScrapeArtifactContext => new ScrapeArtifactContext());

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
        $registry->register($this->app->make(FourDayWeekScraper::class));
        $registry->register($this->app->make(WorkingNomadsScraper::class));
        $registry->register($this->app->make(AdzunaScraper::class));
    }
}
