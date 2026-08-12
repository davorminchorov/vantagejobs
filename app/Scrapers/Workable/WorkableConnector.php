<?php

declare(strict_types=1);

namespace App\Scrapers\Workable;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Workable public widget API.
 *
 * Per SOURCES.md §3.1: anonymous GET against
 * https://apply.workable.com/api/v1/widget/accounts/{subdomain}.
 *
 * Retries (config('scrapers.sources.workable.retry_times'/'retry_sleep_ms'))
 * are wired via ConfigurableConnector.
 */
final class WorkableConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'sources.workable';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://apply.workable.com';
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
            'timeout' => (int) config('scrapers.sources.workable.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.workable.connect_timeout'),
        ];
    }
}
