<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\DjangoNews\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /feed/
 *
 * Django Job Board RSS 2.0 feed. One endpoint, no pagination — the whole
 * active set (typically ~4 jobs) lands in a single response.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/feed/';
    }
}
