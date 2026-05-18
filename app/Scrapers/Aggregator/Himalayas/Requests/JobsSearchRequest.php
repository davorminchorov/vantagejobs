<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Himalayas\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /jobs/api
 *
 * Structured JSON endpoint. Response shape:
 *   { jobs: [...], offset: int, limit: int, totalCount: int }
 *
 * Page size is 20 server-side regardless of any `limit` we send (the param is
 * silently ignored), so we walk `offset` 20 at a time in the scraper loop.
 *
 * NOTE: We deliberately do not pre-filter on timezone or seniority server-side —
 * the parser pipeline downstream reads `timezoneRestrictions[]` and friends
 * to bucket the result, and pre-filtering would discard postings the user
 * later widens their criteria to include.
 *
 * Historical note: the previous `/jobs/api/search` endpoint returned only 2
 * jobs out of 100k for the bare unfiltered query (curated subset). `/jobs/api`
 * is the listing endpoint the public site uses and returns the full corpus.
 * Do NOT pass `sort=recent` or similar — the API silently rejects unknown
 * sort values by returning `jobs: []` (totalCount stays accurate, which makes
 * the failure mode invisible until you look at the payload).
 */
final class JobsSearchRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $offset = 0) {}

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'offset' => (string) $this->offset,
        ];
    }

    public function resolveEndpoint(): string
    {
        return '/jobs/api';
    }
}
