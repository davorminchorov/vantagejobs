<?php

declare(strict_types=1);

namespace App\Scrapers\Greenhouse;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Greenhouse public job board API.
 *
 * Per SOURCES.md §1.1: anonymous GET against
 * https://boards-api.greenhouse.io/v1/boards/{board_token}/jobs.
 *
 * EU-hosted boards (boards.eu.greenhouse.io) are served from a parallel API
 * host, cached on `Company::$ats_host` at detection time and passed in as
 * $baseUrl; null keeps the default US host.
 *
 * Retries (config('scrapers.sources.greenhouse.retry_times'/'retry_sleep_ms'))
 * are wired via ConfigurableConnector.
 */
final class GreenhouseConnector extends ConfigurableConnector
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.greenhouse';
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl ?? 'https://boards-api.greenhouse.io';
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
