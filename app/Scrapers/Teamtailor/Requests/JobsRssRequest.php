<?php

declare(strict_types=1);

namespace App\Scrapers\Teamtailor\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /jobs.rss
 *
 * Teamtailor exposes a per-customer public RSS 2.0 feed at
 * https://{company}.teamtailor.com/jobs.rss containing the currently open
 * positions. No authentication is required.
 */
final class JobsRssRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/jobs.rss';
    }
}
