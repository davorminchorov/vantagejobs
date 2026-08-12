<?php

declare(strict_types=1);

namespace App\Scrapers\Rippling;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Rippling ATS public job-board API.
 *
 * Anonymous GET against https://ats.rippling.com/api/v2/board/{slug}/jobs.
 *
 * Retries (config('scrapers.sources.rippling.retry_times'/'retry_sleep_ms'))
 * are wired via ConfigurableConnector.
 */
final class RipplingConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'sources.rippling';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://ats.rippling.com';
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
            'timeout' => (int) config('scrapers.sources.rippling.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.rippling.connect_timeout'),
        ];
    }
}
