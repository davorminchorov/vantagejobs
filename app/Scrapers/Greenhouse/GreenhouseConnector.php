<?php

declare(strict_types=1);

namespace App\Scrapers\Greenhouse;

use Saloon\Http\Connector;

/**
 * Saloon connector for the Greenhouse public job board API.
 *
 * Per SOURCES.md §1.1: anonymous GET against
 * https://boards-api.greenhouse.io/v1/boards/{board_token}/jobs.
 *
 * Retries are intentionally left to the surrounding orchestrator (scrape:all
 * pool layer) rather than embedded here, so they can be coordinated against
 * the per-source concurrency cap in config/scrapers.php.
 */
final class GreenhouseConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://boards-api.greenhouse.io';
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
            'timeout' => (int) config('scrapers.sources.greenhouse.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.greenhouse.connect_timeout'),
        ];
    }
}
