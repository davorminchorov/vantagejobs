<?php

declare(strict_types=1);

namespace App\Scrapers\Recruitee\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/offers/
 *
 * Returns the list of published offers for a Recruitee tenant. No auth, no
 * conditional-GET support per SOURCES.md §3.4.
 */
final class OffersRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/offers/';
    }
}
