<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\ElixirJobs;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Elixir Jobs RSS feed.
 *
 * Anonymous GET against elixirjobs.net/rss. Concurrency 1, daily cadence.
 * Retries (config('scrapers.aggregators.elixir_jobs.retry_times'/
 * 'retry_sleep_ms')) are wired via ConfigurableConnector.
 *
 * NOTE: the server returns 500 if an explicit
 * `Accept: application/rss+xml` is sent. Omit Accept; default `*\/*` works.
 */
final class ElixirJobsConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.elixir_jobs';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://elixirjobs.net';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => (string) config('scrapers.user_agent'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.aggregators.elixir_jobs.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.elixir_jobs.connect_timeout'),
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
