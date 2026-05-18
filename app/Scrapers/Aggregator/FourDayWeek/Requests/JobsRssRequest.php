<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\FourDayWeek\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /feed
 *
 * Returns the full 4dayweek.io job feed as RSS 2.0. One endpoint, no
 * pagination — the whole curated set lands in a single response.
 *
 * Historical note: the previous `/jobs.rss` endpoint started returning a Next.js
 * 404 HTML page, which surfaced downstream as "malformed RSS XML". `/feed` is
 * the canonical RSS URL the site advertises via `<atom:link rel="self">`.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/feed';
    }
}
