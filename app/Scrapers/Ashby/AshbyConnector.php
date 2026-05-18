<?php

declare(strict_types=1);

namespace App\Scrapers\Ashby;

use Saloon\Http\Connector;

/**
 * Saloon connector for Ashby's public job-board API.
 *
 * SOURCES.md §1.3. Base URL is constant; the company-specific slug is part
 * of the request path. Timeouts come from config/scrapers.php so ops can
 * tune them without code changes.
 */
final class AshbyConnector extends Connector
{
    public function __construct(
        private readonly string $userAgent,
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.ashbyhq.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];
    }
}
