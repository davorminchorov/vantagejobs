<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v1/search?query=Ask+HN+Who+is+hiring&tags=story,author_whoishiring&hitsPerPage=5
 *
 * Algolia returns the most recent "Who is Hiring" threads. The first hit is
 * the latest by default (Algolia HackerNews orders by created_at desc).
 */
final class FindLatestThreadRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/search';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'query' => 'Ask HN Who is hiring',
            'tags' => 'story,author_whoishiring',
            'hitsPerPage' => '5',
        ];
    }
}
