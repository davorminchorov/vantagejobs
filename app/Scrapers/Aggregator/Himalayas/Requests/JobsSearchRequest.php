<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Himalayas\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /jobs/api/search
 *
 * Structured JSON endpoint (per SOURCES.md §2.3). Response shape:
 *   { jobs: [...], offset: int, limit: int, totalCount: int }
 *
 * We deliberately do not pre-filter on timezone or seniority server-side —
 * the parser pipeline downstream reads `timezoneRestrictions[]` and friends
 * to bucket the result, and pre-filtering would discard postings the user
 * later widens their criteria to include.
 *
 * NOTE: Do NOT pass `sort=recent` here. Himalayas' API silently rejects
 * unrecognized sort values by returning `jobs: []` (totalCount stays
 * accurate, which makes the failure mode invisible until you look at the
 * payload). The default order is recent-first, so leaving sort out of the
 * query is what we actually want. Discovered the empty-jobs[] bug after
 * Phase 3's live ingestion run.
 */
final class JobsSearchRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $limit = 50) {}

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'limit' => (string) $this->limit,
        ];
    }

    public function resolveEndpoint(): string
    {
        return '/jobs/api/search';
    }
}
