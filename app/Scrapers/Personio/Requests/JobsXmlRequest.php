<?php

declare(strict_types=1);

namespace App\Scrapers\Personio\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /xml?language=en
 *
 * Personio's public feed returns the company's open positions as XML. The
 * connector supplies the per-company base URL (subdomain + TLD).
 */
final class JobsXmlRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/xml';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'language' => 'en',
        ];
    }
}
