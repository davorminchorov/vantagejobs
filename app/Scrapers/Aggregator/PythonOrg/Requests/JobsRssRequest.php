<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\PythonOrg\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /jobs/feed/rss/
 *
 * Python.org's official RSS feed. One endpoint, no pagination — returns
 * the latest 20 jobs.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/jobs/feed/rss/';
    }
}
