<?php

declare(strict_types=1);

namespace App\Scrapers\Recruitee;

use Saloon\Http\Connector;

/**
 * Saloon connector for the Recruitee public offers API.
 *
 * Per SOURCES.md §3.4: anonymous GET against
 * https://{company}.recruitee.com/api/offers/.
 *
 * The base URL is constructed per-request because the host varies per company
 * (the slug is the customer subdomain).
 */
final class RecruiteeConnector extends Connector
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
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.sources.recruitee.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.recruitee.connect_timeout'),
        ];
    }
}
