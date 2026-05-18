<?php

declare(strict_types=1);

namespace App\Scrapers\Ashby\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /posting-api/job-board/{name}?includeCompensation=true
 *
 * Conditional GET headers (If-None-Match / If-Modified-Since) are forwarded
 * when known so the upstream can answer 304 and save us a parse.
 */
final class JobBoardRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $name,
        private readonly ?string $ifNoneMatch = null,
        private readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/posting-api/job-board/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'includeCompensation' => 'true',
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
