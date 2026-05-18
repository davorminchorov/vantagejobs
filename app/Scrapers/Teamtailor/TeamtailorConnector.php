<?php

declare(strict_types=1);

namespace App\Scrapers\Teamtailor;

use Saloon\Http\Connector;

/**
 * Saloon connector for the Teamtailor public RSS jobs feed.
 *
 * Per SOURCES.md §3.5: anonymous GET against
 * https://{company}.teamtailor.com/jobs.rss.
 *
 * The base URL is constructed per-request because the host varies per company
 * (the slug is the customer subdomain on teamtailor.com).
 */
final class TeamtailorConnector extends Connector
{
    public function __construct(private readonly string $baseUrl) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
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
            'timeout' => (int) config('scrapers.sources.teamtailor.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.teamtailor.connect_timeout'),
        ];
    }
}
