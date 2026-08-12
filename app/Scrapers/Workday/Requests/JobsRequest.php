<?php

declare(strict_types=1);

namespace App\Scrapers\Workday\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /wday/cxs/{tenant}/{site}/jobs
 *
 * Workday's CXS job-search endpoint. No auth required for public postings.
 * Pagination is driven by `offset`/`limit` in the JSON body rather than the
 * query string; the caller walks pages until the returned `jobPostings`
 * array is empty or the cumulative offset reaches `total`. Many tenants
 * silently cap the effective page size at 20 regardless of the `limit`
 * sent, so callers should keep it at 20.
 */
final class JobsRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $tenant,
        private readonly string $site,
        private readonly int $limit = 20,
        private readonly int $offset = 0,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/wday/cxs/{$this->tenant}/{$this->site}/jobs";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'appliedFacets' => (object) [],
            'limit' => $this->limit,
            'offset' => $this->offset,
            'searchText' => '',
        ];
    }
}
