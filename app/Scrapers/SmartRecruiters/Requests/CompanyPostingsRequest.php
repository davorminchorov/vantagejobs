<?php

declare(strict_types=1);

namespace App\Scrapers\SmartRecruiters\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /v1/companies/{companyIdentifier}/postings?limit=100&offset={offset}
 *
 * Public postings endpoint — no auth required. Paginated via offset; the
 * caller walks pages until the returned `content` array is empty or the
 * cumulative offset crosses `totalFound`.
 */
final class CompanyPostingsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $companyIdentifier,
        private readonly int $offset = 0,
        private readonly int $limit = 100,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/v1/companies/{$this->companyIdentifier}/postings";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }
}
