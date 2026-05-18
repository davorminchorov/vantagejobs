<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WorkingNomads\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/exposed_jobs/
 *
 * Returns a flat JSON array of jobs — no envelope, no pagination. Each entry
 * carries `url`, `title`, `company_name`, `category_name`, `tags` (comma-
 * separated string), `location`, `description` (HTML), `pub_date` (ISO).
 */
final class ExposedJobsRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/exposed_jobs/';
    }
}
