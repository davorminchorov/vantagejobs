<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/job-board-api
 *
 * Returns `data[]` with the latest job listings. Pagination is available
 * via `?page=N`; this first integration scrapes only page 1 (latest jobs)
 * and relies on daily cadence to keep up.
 */
final class JobBoardRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/job-board-api';
    }
}
