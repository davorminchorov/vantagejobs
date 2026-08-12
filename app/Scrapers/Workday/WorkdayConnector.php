<?php

declare(strict_types=1);

namespace App\Scrapers\Workday;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the Workday CXS (candidate experience site) job feed.
 *
 * Per SOURCES.md — anonymous POST against
 * https://{tenant}.{wdN}.myworkdayjobs.com/wday/cxs/{tenant}/{site}/jobs.
 *
 * The base URL is constructed per-request because both the tenant and the
 * numbered pod (wd1, wd2, wd3, wd5, wd12, ...) vary per company; the pod is
 * not derivable from the tenant name alone and must be probed (see
 * WorkdayScraper). Retries
 * (config('scrapers.sources.workday.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class WorkdayConnector extends ConfigurableConnector
{
    public function __construct(private readonly string $baseUrl)
    {
        $this->applyRetryConfig($this->configKey());
    }

    protected function configKey(): string
    {
        return 'sources.workday';
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
            'timeout' => (int) config('scrapers.sources.workday.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.workday.connect_timeout'),
        ];
    }
}
