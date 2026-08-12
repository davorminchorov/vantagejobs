<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\DjangoNews;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Support\ConfigurableConnector;
use Illuminate\Container\Container;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for the Django Job Board RSS feed.
 *
 * Anonymous GET against djangojobboard.com/feed/ (originally hosted at
 * jobs.django-news.com — that domain now redirects here). Concurrency
 * capped at 1 in config; polled daily. Retries
 * (config('scrapers.aggregators.django_news.retry_times'/'retry_sleep_ms'))
 * are wired via ConfigurableConnector.
 */
final class DjangoNewsConnector extends ConfigurableConnector
{
    protected function configKey(): string
    {
        return 'aggregators.django_news';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://djangojobboard.com';
    }

    /**
     * Note: omitting an explicit Accept header. Some RSS endpoints in this
     * tier reject `application/rss+xml`; servers that accept it also accept
     * the default `*\/*`. Keeping this consistent across the tier 2 family.
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
            'timeout' => (int) config('scrapers.aggregators.django_news.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.django_news.connect_timeout'),
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
