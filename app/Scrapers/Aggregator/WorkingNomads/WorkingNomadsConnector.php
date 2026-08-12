<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WorkingNomads;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Working Nomads exposed-jobs API.
 *
 * Per SOURCES.md §3.7: anonymous GET against
 * workingnomads.co/api/exposed_jobs/. Returns the full job list in a single
 * flat JSON array — no pagination, no auth. Concurrency stays at 1 to be a
 * polite citizen against a small unmonetized aggregator. Retries
 * (config('scrapers.aggregators.workingnomads.retry_times'/'retry_sleep_ms'))
 * are wired via ConfigurableConnector.
 */
final class WorkingNomadsConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.workingnomads';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://www.workingnomads.co';
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
            'timeout' => (int) config('scrapers.aggregators.workingnomads.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.workingnomads.connect_timeout'),
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
