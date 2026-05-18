<?php

declare(strict_types=1);

namespace App\Scrapers\Lever\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET https://api.lever.co/v0/postings/{site}?mode=json
 *
 * Returns a bare JSON array of postings (not wrapped). Sends conditional
 * headers when provided so unchanged feeds short-circuit at 304.
 */
final class PostingsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $site,
        public readonly ?string $ifNoneMatch = null,
        public readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/v0/postings/{$this->site}";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['mode' => 'json'];
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
