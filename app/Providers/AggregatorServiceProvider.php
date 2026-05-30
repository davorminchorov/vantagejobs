<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scrapers\Aggregator\Adzuna\AdzunaScraper;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\Arbeitnow\ArbeitnowScraper;
use App\Scrapers\Aggregator\Artifacts\ScrapeArtifactContext;
use App\Scrapers\Aggregator\DjangoNews\DjangoNewsScraper;
use App\Scrapers\Aggregator\ElixirJobs\ElixirJobsScraper;
use App\Scrapers\Aggregator\FourDayWeek\FourDayWeekScraper;
use App\Scrapers\Aggregator\HackerNews\HackerNewsScraper;
use App\Scrapers\Aggregator\HexHire\HexHireScraper;
use App\Scrapers\Aggregator\Himalayas\HimalayasScraper;
use App\Scrapers\Aggregator\Jobicy\JobicyScraper;
use App\Scrapers\Aggregator\Larajobs\LarajobsScraper;
use App\Scrapers\Aggregator\PythonOrg\PythonOrgScraper;
use App\Scrapers\Aggregator\SaveFixtureAction;
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
 * Binds the aggregator registry (self-populated in its factory closure with
 * every concrete scraper), the shared ParserPipeline (one set of 12 extractors
 * used by every aggregator scraper), and the related scoped services.
 *
 * Scoped (matching ScraperServiceProvider) so Octane resets state between
 * requests rather than holding stale closures forward. Population happens in
 * the factory closure, not boot(): boot() runs once per process, but a
 * long-running queue worker (Horizon) flushes scoped instances between jobs, so
 * a boot-populated registry would be empty for every job after the first.
 */
final class AggregatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AggregatorRegistry::class, function ($app): AggregatorRegistry {
            $registry = new AggregatorRegistry();
            $registry->register($app->make(HackerNewsScraper::class));
            $registry->register($app->make(RemotiveScraper::class));
            $registry->register($app->make(HimalayasScraper::class));
            $registry->register($app->make(WeWorkRemotelyScraper::class));
            $registry->register($app->make(RemoteOkScraper::class));
            $registry->register($app->make(FourDayWeekScraper::class));
            $registry->register($app->make(WorkingNomadsScraper::class));
            $registry->register($app->make(AdzunaScraper::class));
            $registry->register($app->make(LarajobsScraper::class));
            $registry->register($app->make(JobicyScraper::class));
            $registry->register($app->make(ArbeitnowScraper::class));
            $registry->register($app->make(PythonOrgScraper::class));
            $registry->register($app->make(DjangoNewsScraper::class));
            $registry->register($app->make(ElixirJobsScraper::class));
            $registry->register($app->make(HexHireScraper::class));

            return $registry;
        });

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
}
