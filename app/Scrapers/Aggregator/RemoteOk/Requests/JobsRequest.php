<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\RemoteOk\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api
 *
 * Returns a JSON array. The first element is metadata/legal — the scraper
 * skips index 0 and iterates the remaining job records.
 */
final class JobsRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api';
    }
}
