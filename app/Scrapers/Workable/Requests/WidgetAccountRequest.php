<?php

declare(strict_types=1);

namespace App\Scrapers\Workable\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v1/widget/accounts/{subdomain}
 *
 * Anonymous public widget endpoint that returns the company's open jobs as JSON.
 */
final class WidgetAccountRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $subdomain) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/widget/accounts/{$this->subdomain}";
    }
}
