<?php

declare(strict_types=1);

namespace App\Scrapers\BambooHr;

use App\Scrapers\Personio\PersonioConnector;
use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the BambooHR public careers feed.
 *
 * Anonymous GET against https://{slug}.bamboohr.com/careers/list. The base
 * URL is constructed per-request because the host varies per company (the
 * slug is the customer subdomain), following the same pattern as
 * {@see PersonioConnector}. Retries
 * (config('scrapers.sources.bamboohr.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 *
 * An invalid subdomain 302-redirects to the www.bamboohr.com marketing site
 * instead of returning a 404, so redirect-following is disabled here
 * ('allow_redirects' => false). The scraper treats any 3xx response as
 * slug-not-found and maps it to context.http_status = 404.
 */
final class BambooHrConnector extends ConfigurableConnector
{
    public function __construct(private readonly string $baseUrl)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.bamboohr';
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
            'timeout' => (int) config('scrapers.sources.bamboohr.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.bamboohr.connect_timeout'),
            'allow_redirects' => false,
        ];
    }
}
