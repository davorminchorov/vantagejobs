<?php

declare(strict_types=1);

namespace App\Signals\Layoffs;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Fetches the TrueUp /layoffs HTML page. SOURCES.md §4.2 — no documented
 * API, weekly cadence. Kept thin so tests can fake the HTTP factory.
 */
final readonly class TrueUpClient
{
    public function __construct(private HttpFactory $http) {}

    public function fetch(): string
    {
        $url = (string) config('scrapers.trueup.url');
        $timeout = (int) config('scrapers.trueup.timeout', 30);
        $connectTimeout = (int) config('scrapers.trueup.connect_timeout', 5);

        $response = $this->http
            ->withUserAgent((string) config('scrapers.user_agent'))
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->acceptJson()
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get($url);

        $response->throw();

        return (string) $response->body();
    }
}
