<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WeWorkRemotely\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /remote-jobs.rss or /categories/{slug}.rss
 *
 * One Saloon request parameterised by an optional category slug, per
 * SOURCES.md §2.4. A null slug resolves to the umbrella feed
 * (`/remote-jobs.rss`), which covers every category; a slug resolves to
 * that category's feed. A single class keeps Saloon::fake() targeting
 * trivial and avoids near-identical sibling classes.
 */
final class CategoryFeedRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(public readonly ?string $categorySlug = null) {}

    public function resolveEndpoint(): string
    {
        return $this->categorySlug === null
            ? '/remote-jobs.rss'
            : '/categories/'.$this->categorySlug.'.rss';
    }
}
