<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Remotive;

use Saloon\Http\Connector;

/**
 * Saloon connector for the Remotive remote-jobs API.
 *
 * Per SOURCES.md §2.2: anonymous GET against remotive.com/api/remote-jobs.
 * Strict rate limit (≤ 2 req/min); concurrency capped at 1 in
 * config('scrapers.aggregators.remotive.concurrency'), polled once daily.
 */
final class RemotiveConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://remotive.com';
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
            'timeout' => (int) config('scrapers.aggregators.remotive.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.remotive.connect_timeout'),
        ];
    }
}
