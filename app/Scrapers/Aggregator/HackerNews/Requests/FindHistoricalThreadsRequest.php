<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v1/search_by_date?query=Ask+HN+Who+is+hiring&tags=story,author_whoishiring&hitsPerPage=N
 *
 * The Algolia "by_date" search variant sorts hits by `created_at_i` desc, which
 * for "Ask HN: Who is Hiring?" gives us the most recent N monthly threads in
 * order. Used by `scrape:backfill --source=hackernews --days=N` (treating one
 * "day" as one month for HN, since the monthly thread is the smallest unit of
 * history available).
 */
final class FindHistoricalThreadsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $months) {}

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
            'hitsPerPage' => (string) max(1, $this->months),
        ];
    }
}
