<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for jobicy.com's v2 remote-jobs JSON API.
 *
 * Anonymous GET. Jobicy publishes a 1-request-per-hour soft limit; daily
 * cadence (config('scrapers.aggregators.jobicy.concurrency') = 1,
 * AggregatorSourceType::JOBICY->defaultCadenceMinutes() = 1440) keeps us
 * well inside it.
 */
final class JobicyConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://jobicy.com';
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
            'timeout' => (int) config('scrapers.aggregators.jobicy.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.jobicy.connect_timeout'),
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
