<?php

declare(strict_types=1);

namespace App\Scrapers\Personio;

use Saloon\Http\Connector;

/**
 * Saloon connector for the Personio public job feed.
 *
 * Per SOURCES.md §3.3: anonymous GET against
 * https://{company}.jobs.personio.{de|com}/xml?language=en.
 *
 * The base URL is constructed per-request because the host varies per company
 * (the slug is the customer subdomain and the TLD can be .de or .com).
 */
final class PersonioConnector extends Connector
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
            'Accept' => 'application/xml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.sources.personio.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.personio.connect_timeout'),
        ];
    }
}
