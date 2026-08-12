<?php

declare(strict_types=1);

namespace App\Scrapers\Lever;

use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Connector for the Lever public Postings API.
 *
 * Endpoint base: https://api.lever.co
 * Identifies the agent via the configured User-Agent and applies the
 * per-source timeouts from config/scrapers.php. Retries
 * (config('scrapers.sources.lever.retry_times'/'retry_sleep_ms')) are wired
 * via ConfigurableConnector.
 */
final class LeverConnector extends ConfigurableConnector
{
    public function __construct(private readonly ConfigRepository $appConfig)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.lever';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.lever.co';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => (string) $this->appConfig->get('scrapers.user_agent'),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) $this->appConfig->get('scrapers.sources.lever.timeout', 15),
            'connect_timeout' => (int) $this->appConfig->get('scrapers.sources.lever.connect_timeout', 5),
        ];
    }
}
