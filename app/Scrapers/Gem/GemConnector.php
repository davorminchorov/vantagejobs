<?php

declare(strict_types=1);

namespace App\Scrapers\Gem;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Gem job board API.
 *
 * GET https://api.gem.com/job_board/v0/{vanity_url_path}/job_posts/ — the
 * vanity path is configured per-company under Gem's Admin > Job boards &
 * posts settings and stored as Company::$ats_slug. Boards are browsable at
 * https://jobs.gem.com/{slug}, but the API itself lives on api.gem.com.
 *
 * Retries (config('scrapers.sources.gem.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class GemConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'sources.gem';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.gem.com';
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
            'timeout' => (int) config('scrapers.sources.gem.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.gem.connect_timeout'),
        ];
    }
}
