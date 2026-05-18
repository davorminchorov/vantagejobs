<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews;

use Saloon\Http\Connector;

/**
 * Saloon connector for the Algolia HackerNews API.
 *
 * Per SOURCES.md §2.1: anonymous GET against hn.algolia.com. Algolia returns
 * the full thread tree from /api/v1/items/{id} in one call, which beats the
 * Firebase HN API (which would require recursive fetches per child).
 */
final class HackerNewsAlgoliaConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://hn.algolia.com';
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
            'timeout' => (int) config('scrapers.aggregators.hackernews.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.hackernews.connect_timeout'),
        ];
    }
}
