<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scrapers\Ashby\AshbyScraper;
use App\Scrapers\Greenhouse\GreenhouseScraper;
use App\Scrapers\Lever\LeverScraper;
use App\Scrapers\Personio\PersonioScraper;
use App\Scrapers\Recruitee\RecruiteeScraper;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\SmartRecruiters\SmartRecruitersScraper;
use App\Scrapers\Workable\WorkableScraper;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the scraper registry as a scoped service and registers each concrete
 * scraper into it. Scoped (not singleton) so Octane wipes the registry between
 * requests rather than holding container-resolved closures across reboots.
 *
 * Concrete scraper bindings (Greenhouse, Lever, Ashby) land here as they're
 * implemented in steps 4-6.
 */
final class ScraperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ScraperRegistry::class, fn (): ScraperRegistry => new ScraperRegistry());
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/scrapers.php', 'scrapers');

        $registry = $this->app->make(ScraperRegistry::class);
        $registry->register($this->app->make(GreenhouseScraper::class));
        $registry->register($this->app->make(LeverScraper::class));
        $registry->register($this->app->make(AshbyScraper::class));
        $registry->register($this->app->make(WorkableScraper::class));
        $registry->register($this->app->make(SmartRecruitersScraper::class));
        $registry->register($this->app->make(PersonioScraper::class));
        $registry->register($this->app->make(RecruiteeScraper::class));
    }
}
