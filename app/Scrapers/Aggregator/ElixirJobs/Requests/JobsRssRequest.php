<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\ElixirJobs\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /rss
 *
 * Returns the full Elixir Jobs feed as RSS 2.0. One endpoint, no
 * pagination — the whole active set lands in a single response.
 *
 * NOTE: do NOT use /feed — that endpoint returns 404.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/rss';
    }
}
