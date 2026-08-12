<?php

declare(strict_types=1);

namespace App\Scrapers\Lever;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Connector for the Lever public Postings API.
 *
 * Endpoint base: https://api.lever.co. EU-hosted boards (jobs.eu.lever.co)
 * are served from https://api.eu.lever.co, cached on `Company::$ats_host` at
 * detection time and passed in as $baseUrl; null keeps the default US host.
 *
 * Identifies the agent via the configured User-Agent and applies the
 * per-source timeouts from config/scrapers.php. Retries
 * (config('scrapers.sources.lever.retry_times'/'retry_sleep_ms')) are wired
 * via ConfigurableConnector.
 */
final class LeverConnector extends ConfigurableConnector
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.lever';
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl ?? 'https://api.lever.co';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => (string) config('scrapers.user_agent'),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.sources.lever.timeout', 15),
            'connect_timeout' => (int) config('scrapers.sources.lever.connect_timeout', 5),
        ];
    }
}
