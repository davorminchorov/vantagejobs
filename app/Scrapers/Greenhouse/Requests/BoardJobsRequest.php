<?php

declare(strict_types=1);

namespace App\Scrapers\Greenhouse\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /v1/boards/{board_token}/jobs?content=true
 *
 * Conditional-GET aware: forwards If-None-Match / If-Modified-Since when the
 * caller has previously stored values. The Greenhouse API honours both and
 * returns 304 when the board hasn't changed.
 */
final class BoardJobsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $boardToken,
        private readonly ?string $ifNoneMatch = null,
        private readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/v1/boards/{$this->boardToken}/jobs";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'content' => 'true',
        ];
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
