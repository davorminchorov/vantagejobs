<?php

declare(strict_types=1);

namespace App\Scrapers\SmartRecruiters;

use Saloon\Http\Connector;

/**
 * Saloon connector for the SmartRecruiters public postings API.
 *
 * Per SOURCES.md §3.2: anonymous GET against
 * https://api.smartrecruiters.com/v1/companies/{companyIdentifier}/postings.
 *
 * Retries are intentionally left to the surrounding orchestrator (scrape:all
 * pool layer) rather than embedded here, so they can be coordinated against
 * the per-source concurrency cap in config/scrapers.php.
 */
final class SmartRecruitersConnector extends Connector
{
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
