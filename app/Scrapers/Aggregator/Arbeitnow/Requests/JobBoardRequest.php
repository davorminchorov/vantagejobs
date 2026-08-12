<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/job-board-api?page={page}
 *
 * Returns `data[]` with one page of listings plus `links.next` (a full URL,
 * or null on the last page). Pagination is walked in the scraper via the
 * `page` query param; `links.next` is what tells the scraper whether more
 * pages remain.
 */
final class JobBoardRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $page = 1) {}

    public function resolveEndpoint(): string
    {
        return '/api/job-board-api';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'page' => (string) $this->page,
        ];
    }
}
