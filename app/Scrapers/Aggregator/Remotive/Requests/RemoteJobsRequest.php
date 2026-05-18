<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Remotive\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/remote-jobs?category=software-dev
 *
 * Returns a JSON envelope with `job-count` and `jobs[]`. We iterate `jobs`;
 * the top-level "0-legal-notice" key is informational and not part of the
 * job list itself.
 */
final class RemoteJobsRequest extends Request
{
    protected Method $method = Method::GET;

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'category' => 'software-dev',
        ];
    }

    public function resolveEndpoint(): string
    {
        return '/api/remote-jobs';
    }
}
