<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\FourDayWeek\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /jobs.rss
 *
 * Returns the full 4dayweek.io job feed as RSS 2.0. One endpoint, no
 * pagination — the whole curated set lands in a single response.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/jobs.rss';
    }
}
