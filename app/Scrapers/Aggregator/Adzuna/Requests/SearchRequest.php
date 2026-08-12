<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Adzuna\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /v1/api/jobs/{country}/search/{page}
 *
 * Returns `{ count, results[] }`. Up to 50 results per page; the scraper
 * pages until either pages_per_country is reached or a short page comes
 * back. `category=it-jobs` scopes to tech roles; an optional free-text
 * `what` filter (config('scrapers.aggregators.adzuna.what')) narrows further
 * when set - empty by default so category alone decides the result set.
 */
final class SearchRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $country,
        public readonly int $page,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/v1/api/jobs/{$this->country}/search/{$this->page}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        $query = [
            'app_id' => (string) config('scrapers.aggregators.adzuna.app_id'),
            'app_key' => (string) config('scrapers.aggregators.adzuna.app_key'),
            'results_per_page' => 50,
            'category' => 'it-jobs',
        ];

        $what = (string) config('scrapers.aggregators.adzuna.what', '');
        if ($what !== '') {
            $query['what'] = $what;
        }

        return $query;
    }
}
