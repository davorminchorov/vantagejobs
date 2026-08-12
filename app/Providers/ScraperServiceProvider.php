<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scrapers\Ashby\AshbyScraper;
use App\Scrapers\BambooHr\BambooHrScraper;
use App\Scrapers\Gem\GemScraper;
use App\Scrapers\Greenhouse\GreenhouseScraper;
use App\Scrapers\Lever\LeverScraper;
use App\Scrapers\Personio\PersonioScraper;
use App\Scrapers\Pinpoint\PinpointScraper;
use App\Scrapers\Recruitee\RecruiteeScraper;
use App\Scrapers\Rippling\RipplingScraper;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\SmartRecruiters\SmartRecruitersScraper;
use App\Scrapers\Teamtailor\TeamtailorScraper;
use App\Scrapers\Workable\WorkableScraper;
use App\Scrapers\Workday\WorkdayScraper;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the scraper registry as a scoped service, self-populated in its factory
 * closure with every concrete scraper. Scoped (not singleton) so Octane resets
 * the registry between requests rather than holding container-resolved closures
 * across reboots.
 *
 * Population MUST happen in the factory closure, not in boot(): boot() runs once
 * per process, but a long-running queue worker (Horizon) flushes scoped
 * instances between jobs. A registry populated only at boot would be empty for
 * every job after the first. Registering inside the closure means the registry
 * is fully populated every time a fresh scope creates it.
 */
final class ScraperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/scrapers.php', 'scrapers');

        $this->app->scoped(ScraperRegistry::class, function ($app): ScraperRegistry {
            $registry = new ScraperRegistry;
            $registry->register($app->make(GreenhouseScraper::class));
            $registry->register($app->make(LeverScraper::class));
            $registry->register($app->make(AshbyScraper::class));
            $registry->register($app->make(WorkableScraper::class));
            $registry->register($app->make(SmartRecruitersScraper::class));
            $registry->register($app->make(PersonioScraper::class));
            $registry->register($app->make(RecruiteeScraper::class));
            $registry->register($app->make(TeamtailorScraper::class));
            $registry->register($app->make(PinpointScraper::class));
            $registry->register($app->make(BambooHrScraper::class));
            $registry->register($app->make(RipplingScraper::class));
            $registry->register($app->make(GemScraper::class));
            $registry->register($app->make(WorkdayScraper::class));

            return $registry;
        });
    }
}
