<?php

declare(strict_types=1);

namespace App\Scrapers\Gem\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /job_board/v0/{vanity_url_path}/job_posts/
 *
 * Conditional-GET aware: forwards If-None-Match / If-Modified-Since when the
 * caller has previously stored values. Gem honours both and returns 304 when
 * the board hasn't changed.
 */
final class JobPostsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $vanityUrlPath,
        private readonly ?string $ifNoneMatch = null,
        private readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/job_board/v0/{$this->vanityUrlPath}/job_posts/";
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
