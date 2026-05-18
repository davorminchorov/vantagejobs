<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for arbeitnow.com's job-board JSON API.
 *
 * Anonymous GET. EU/Germany-focused. No published rate limit; we run daily
 * with concurrency 1 to stay polite.
 */
final class ArbeitnowConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://www.arbeitnow.com';
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
            'timeout' => (int) config('scrapers.aggregators.arbeitnow.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.arbeitnow.connect_timeout'),
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
