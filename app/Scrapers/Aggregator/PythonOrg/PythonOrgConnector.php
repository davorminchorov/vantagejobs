<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\PythonOrg;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Python.org official jobs RSS feed.
 *
 * Anonymous GET. Plain RSS 2.0 endpoint at /jobs/feed/rss/. Concurrency
 * capped at 1 in config; polled daily. Retries
 * (config('scrapers.aggregators.python_org.retry_times'/'retry_sleep_ms')) are
 * wired via ConfigurableConnector.
 */
final class PythonOrgConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.python_org';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://www.python.org';
    }

    /**
     * Note: omitting an explicit Accept header. Some RSS endpoints in this
     * tier (Elixir Jobs, HexHire) reject `application/rss+xml`; servers that
     * accept it also accept the default `*\/*`. Keeping this consistent across
     * the tier 2 family.
     *
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
            'timeout' => (int) config('scrapers.aggregators.python_org.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.python_org.connect_timeout'),
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
