<?php

declare(strict_types=1);

namespace App\Scrapers\Rippling\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v2/board/{slug}/jobs?page={page}&pageSize={pageSize}
 *
 * Public job-board endpoint - no auth required. Paginated via a zero-based
 * `page` index; the response carries `totalPages`/`totalItems`, so the
 * caller walks pages until `items` comes back empty or `page + 1 >=
 * totalPages`.
 *
 * Conditional-GET aware: forwards If-None-Match / If-Modified-Since when the
 * caller has previously stored values. Only the first page of a run sets
 * these - live probing of the endpoint returned no ETag/Last-Modified
 * response headers, so this is forward-compatible plumbing rather than a
 * confirmed 304 contract.
 */
final class BoardJobsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $boardSlug,
        private readonly int $page = 0,
        private readonly int $pageSize = 100,
        private readonly ?string $ifNoneMatch = null,
        private readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v2/board/{$this->boardSlug}/jobs";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'page' => $this->page,
            'pageSize' => $this->pageSize,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        $headers = [];

        if ($this->ifNoneMatch !== null && $this->ifNoneMatch !== '') {
            $headers['If-None-Match'] = $this->ifNoneMatch;
        }

        if ($this->ifModifiedSince !== null && $this->ifModifiedSince !== '') {
            $headers['If-Modified-Since'] = $this->ifModifiedSince;
        }

        return $headers;
    }
}
