<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Himalayas\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /jobs/api/search
 *
 * Structured JSON endpoint (per SOURCES.md §2.3). Response shape:
 *   { jobs: [...], total: int }
 *
 * We deliberately do not pre-filter on timezone or seniority server-side —
 * the parser pipeline downstream reads `timezoneRestrictions[]` and friends
 * to bucket the result, and pre-filtering would discard postings the user
 * later widens their criteria to include. `sort=recent` keeps the most
 * recently-published postings at the top, which matters when the response
 * is implicitly paginated.
 */
final class JobsSearchRequest extends Request
{
    protected Method $method = Method::GET;

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'sort' => 'recent',
        ];
    }

    public function resolveEndpoint(): string
    {
        return '/jobs/api/search';
    }
}
