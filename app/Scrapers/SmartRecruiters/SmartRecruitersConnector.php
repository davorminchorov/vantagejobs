<?php

declare(strict_types=1);

namespace App\Scrapers\SmartRecruiters;

use App\Scrapers\Support\ConfigurableConnector;

/**
 * Saloon connector for the SmartRecruiters public postings API.
 *
 * Per SOURCES.md §3.2: anonymous GET against
 * https://api.smartrecruiters.com/v1/companies/{companyIdentifier}/postings.
 *
 * Retries (config('scrapers.sources.smartrecruiters.retry_times'/
 * 'retry_sleep_ms')) are wired via ConfigurableConnector.
 */
final class SmartRecruitersConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'sources.smartrecruiters';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.smartrecruiters.com';
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
            'timeout' => (int) config('scrapers.sources.smartrecruiters.timeout'),
            'connect_timeout' => (int) config('scrapers.sources.smartrecruiters.connect_timeout'),
        ];
    }
}
