<?php

declare(strict_types=1);

namespace App\Scrapers\Lever;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Saloon\Http\Connector;

/**
 * Connector for the Lever public Postings API.
 *
 * Endpoint base: https://api.lever.co
 * Identifies the agent via the configured User-Agent and applies the
 * per-source timeouts from config/scrapers.php.
 */
final class LeverConnector extends Connector
{
    public function __construct(private readonly ConfigRepository $appConfig) {}

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
