<?php

declare(strict_types=1);

namespace App\Scrapers\Personio;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Personio public job feed.
 *
 * Per SOURCES.md §3.3: anonymous GET against
 * https://{company}.jobs.personio.{de|com}/xml?language=en.
 *
 * The base URL is constructed per-request because the host varies per company
 * (the slug is the customer subdomain and the TLD can be .de or .com). Retries
 * (config('scrapers.sources.personio.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class PersonioConnector extends ConfigurableConnector
{
    public function __construct(private readonly string $baseUrl)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.personio';
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
