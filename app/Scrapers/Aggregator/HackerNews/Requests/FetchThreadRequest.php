<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v1/items/{story_id}
 *
 * Returns the full thread tree (top-level + nested children) in a single
 * response. We only iterate top-level `children`; nested replies are not
 * job postings.
 */
final class FetchThreadRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $storyId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/items/{$this->storyId}";
    }
}
