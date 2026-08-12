<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Himalayas;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Himalayas remote-jobs API.
 *
 * Per SOURCES.md §2.3: anonymous GET against himalayas.app. Rate limit is
 * non-numeric but returns 429 if abused; their cache refreshes every 24h, so
 * concurrency stays at 1 and the scheduler polls once daily. Retries
 * (config('scrapers.aggregators.himalayas.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector, which also gives us a real retry on the
 * 429s this API returns when abused.
 */
final class HimalayasConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.himalayas';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://himalayas.app';
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
            'timeout' => (int) config('scrapers.aggregators.himalayas.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.himalayas.connect_timeout'),
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
