<?php

declare(strict_types=1);

namespace App\Providers;

use App\Scrapers\Aggregator\AggregatorRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the aggregator registry as a scoped service. Concrete scraper
 * bindings (HN, Remotive, Himalayas, WWR, RemoteOK) land here as they're
 * implemented in steps 7-11.
 *
 * Mirrors ScraperServiceProvider in structure to keep the two pipelines
 * symmetric without coupling them. Aggregator config sits under
 * config('scrapers.aggregators.{type}') — same file, separate namespace.
 */
final class AggregatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AggregatorRegistry::class, fn (): AggregatorRegistry => new AggregatorRegistry());
    }
}
