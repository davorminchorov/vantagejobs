<?php

declare(strict_types=1);

namespace App\Scrapers\Pinpoint\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /postings.json
 *
 * Conditional-GET aware: forwards If-None-Match / If-Modified-Since when the
 * caller has previously stored values. A live probe against
 * workwithus.pinpointhq.com confirmed the endpoint sends a weak ETag on every
 * response and honours a matching If-None-Match with a 304.
 */
final class PostingsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?string $ifNoneMatch = null,
        private readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/postings.json';
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
