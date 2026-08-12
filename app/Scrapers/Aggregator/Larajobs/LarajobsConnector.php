<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Larajobs;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the LaraJobs public RSS feed.
 *
 * Anonymous GET against larajobs.com/feed — a small curated feed of
 * Laravel-specific job postings. Concurrency capped at 1 in
 * config('scrapers.aggregators.larajobs.concurrency'); polled daily. Retries
 * (config('scrapers.aggregators.larajobs.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class LarajobsConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.larajobs';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://larajobs.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => (string) config('scrapers.user_agent'),
            'Accept' => 'application/rss+xml, application/xml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.aggregators.larajobs.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.larajobs.connect_timeout'),
        ];
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            Container::getInstance()->make(RecordResponseArtifact::class),
            'recordResponseArtifact',
        );
    }
}
