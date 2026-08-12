<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HexHire;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the HexHire (Elixir-focused) RSS feed.
 *
 * Anonymous GET against hexhire.io/rss/jobs.xml. Concurrency 1, daily.
 * Retries (config('scrapers.aggregators.hexhire.retry_times'/
 * 'retry_sleep_ms')) are wired via ConfigurableConnector.
 *
 * NOTE: the server returns 406 if `Accept: application/rss+xml` is sent.
 * Omit Accept; default `*\/*` works.
 */
final class HexHireConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.hexhire';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://hexhire.io';
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
            'timeout' => (int) config('scrapers.aggregators.hexhire.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.hexhire.connect_timeout'),
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
