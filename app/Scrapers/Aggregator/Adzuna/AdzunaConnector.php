<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Adzuna;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Adzuna aggregator API.
 *
 * Per SOURCES.md §3.8: authenticated GET against api.adzuna.com requiring
 * app_id + app_key (free developer registration). One request returns up to
 * 50 results for a single country; the scraper pages through
 * `pages_per_country` pages for each configured country code.
 */
final class AdzunaConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.adzuna.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => (string) config('scrapers.user_agent'),
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.aggregators.adzuna.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.adzuna.connect_timeout'),
        ];
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            Container::getInstance()->make(RecordResponseArtifact::class),
            'recordResponseArtifact',
        );
    }
}
