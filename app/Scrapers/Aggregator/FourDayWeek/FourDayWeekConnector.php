<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\FourDayWeek;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the 4dayweek.io public RSS feed.
 *
 * Per SOURCES.md §3.6: anonymous GET against 4dayweek.io/jobs.rss — a curated
 * feed (~2,500 entries) of reduced-schedule employers. Concurrency capped at
 * 1 in config('scrapers.aggregators.fourdayweek.concurrency'); polled daily.
 */
final class FourDayWeekConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://4dayweek.io';
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
            'timeout' => (int) config('scrapers.aggregators.fourdayweek.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.fourdayweek.connect_timeout'),
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
