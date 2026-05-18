<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\ElixirJobs;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Elixir Jobs RSS feed.
 *
 * Anonymous GET against elixirjobs.net/rss. Concurrency 1, daily cadence.
 *
 * NOTE: the server returns 500 if an explicit
 * `Accept: application/rss+xml` is sent. Omit Accept; default `*\/*` works.
 */
final class ElixirJobsConnector extends Connector
{
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
