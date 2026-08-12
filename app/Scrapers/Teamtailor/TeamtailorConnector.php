<?php

declare(strict_types=1);

namespace App\Scrapers\Teamtailor;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Teamtailor public RSS jobs feed.
 *
 * Per SOURCES.md §3.5: anonymous GET against
 * https://{company}.teamtailor.com/jobs.rss.
 *
 * The base URL is constructed per-request because the host varies per company
 * (the slug is the customer subdomain on teamtailor.com). Retries
 * (config('scrapers.sources.teamtailor.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class TeamtailorConnector extends ConfigurableConnector
{
    public function __construct(private readonly string $baseUrl)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.teamtailor';
    }

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
