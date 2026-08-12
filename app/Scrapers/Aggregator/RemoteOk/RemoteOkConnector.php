<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\RemoteOk;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the RemoteOK public JSON API.
 *
 * Per SOURCES.md §2.5: anonymous GET against remoteok.com/api. Rate limits
 * are undocumented; polite daily polling is fine. Concurrency capped at 1
 * in config('scrapers.aggregators.remoteok.concurrency'). Retries
 * (config('scrapers.aggregators.remoteok.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class RemoteOkConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.remoteok';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://remoteok.com';
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
            'timeout' => (int) config('scrapers.aggregators.remoteok.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.remoteok.connect_timeout'),
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
