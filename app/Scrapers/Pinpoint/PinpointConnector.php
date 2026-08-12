<?php

declare(strict_types=1);

namespace App\Scrapers\Pinpoint;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Pinpoint public postings JSON endpoint.
 *
 * Per https://developers.pinpointhq.com/docs/jobs-json-endpoint: anonymous
 * GET against https://{slug}.pinpointhq.com/postings.json.
 *
 * The base URL is constructed per-request because the host is the customer's
 * own Pinpoint subdomain (the slug). Retries
 * (config('scrapers.sources.pinpoint.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class PinpointConnector extends ConfigurableConnector
{
    public function __construct(private readonly string $baseUrl)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.pinpoint';
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
            'timeout' => (int) config('scrapers.sources.pinpoint.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.pinpoint.connect_timeout'),
        ];
    }
}
