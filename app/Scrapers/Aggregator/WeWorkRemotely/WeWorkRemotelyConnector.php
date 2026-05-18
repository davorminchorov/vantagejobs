<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WeWorkRemotely;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the We Work Remotely public RSS feeds.
 *
 * Per SOURCES.md §2.4: anonymous GET against weworkremotely.com category
 * RSS endpoints. ToS forbids non-RSS scraping; the authenticated partner API
 * is intentionally out of scope. Concurrency capped at 1 in
 * config('scrapers.aggregators.weworkremotely.concurrency'), polled daily.
 */
final class WeWorkRemotelyConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://weworkremotely.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => (string) config('scrapers.user_agent'),
            'Accept' => 'application/rss+xml, application/xml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.aggregators.weworkremotely.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.weworkremotely.connect_timeout'),
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
