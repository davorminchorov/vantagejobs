<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v2/remote-jobs?count=50&industry=dev
 *
 * Returns a JSON envelope with `jobs[]`. We pin to `industry=dev` to scope
 * to the engineering subset of Jobicy's catalogue. `count` is
 * config('scrapers.aggregators.jobicy.count') - 50 is the API's documented
 * per-request cap; it offers no pagination and no total-count field, so a
 * "dev" industry with more than 50 active postings at poll time is an
 * upstream limitation this scraper cannot detect or recover from.
 */
final class RemoteJobsRequest extends Request
{
    protected Method $method = Method::GET;

    /**
     * @return array<string, string|int>
     */
    protected function defaultQuery(): array
    {
        return [
            'count' => (int) config('scrapers.aggregators.jobicy.count', 50),
            'industry' => 'dev',
        ];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v2/remote-jobs';
    }
}
