<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WeWorkRemotely\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /categories/{slug}.rss
 *
 * One Saloon request parameterised by category slug — the three category
 * feeds (back-end, full-stack, programming) differ only by path. A single
 * class keeps Saloon::fake() targeting trivial and avoids the three
 * near-identical subclasses that would otherwise be needed.
 */
final class CategoryFeedRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(public readonly string $categorySlug) {}

    public function resolveEndpoint(): string
    {
        return '/categories/'.$this->categorySlug.'.rss';
    }
}
