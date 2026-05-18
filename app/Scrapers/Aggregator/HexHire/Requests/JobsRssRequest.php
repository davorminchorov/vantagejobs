<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HexHire\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /rss/jobs.xml
 *
 * Returns the full HexHire Elixir job feed as RSS 2.0. One endpoint, no
 * pagination — the whole active set lands in a single response.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/rss/jobs.xml';
    }
}
