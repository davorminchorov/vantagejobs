<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\RemoteOk;

use Saloon\Http\Connector;

/**
 * Saloon connector for the RemoteOK public JSON API.
 *
 * Per SOURCES.md §2.5: anonymous GET against remoteok.com/api. Rate limits
 * are undocumented; polite daily polling is fine. Concurrency capped at 1
 * in config('scrapers.aggregators.remoteok.concurrency').
 */
final class RemoteOkConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://remoteok.com';
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
            'timeout' => (int) config('scrapers.aggregators.remoteok.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.remoteok.connect_timeout'),
        ];
    }
}
