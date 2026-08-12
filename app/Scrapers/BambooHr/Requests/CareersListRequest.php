<?php

declare(strict_types=1);

namespace App\Scrapers\BambooHr\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /careers/list
 *
 * Conditional-GET aware: forwards If-None-Match / If-Modified-Since when the
 * caller has previously stored values. BambooHR's behaviour toward these
 * headers is unconfirmed; sending them is harmless if ignored.
 */
final class CareersListRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?string $ifNoneMatch = null,
        private readonly ?string $ifModifiedSince = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/careers/list';
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
