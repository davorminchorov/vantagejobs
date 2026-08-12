<?php

declare(strict_types=1);

namespace App\Scrapers\Recruitee;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Recruitee public offers API.
 *
 * Per SOURCES.md §3.4: anonymous GET against
 * https://{company}.recruitee.com/api/offers/.
 *
 * The base URL is constructed per-request because the host varies per company
 * (the slug is the customer subdomain). Retries
 * (config('scrapers.sources.recruitee.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class RecruiteeConnector extends ConfigurableConnector
{
    public function __construct(private readonly string $baseUrl)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.recruitee';
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
