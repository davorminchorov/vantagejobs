<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v1/search_by_date?query=Ask+HN+Who+is+hiring&tags=story,author_whoishiring&hitsPerPage=1
 *
 * Algolia's `search_by_date` variant sorts hits by `created_at_i` desc — so
 * the first hit is the most recent "Ask HN: Who is hiring?" thread.
 *
 * NOTE: do not use the plain `/api/v1/search` endpoint here. That one ranks
 * by relevance/popularity, so a heavily-upvoted older thread can outrank the
 * current month's thread and we'd silently scrape years-old data.
 */
final class FindLatestThreadRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/search_by_date';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'query' => 'Ask HN Who is hiring',
            'tags' => 'story,author_whoishiring',
            'hitsPerPage' => '1',
        ];
    }
}
