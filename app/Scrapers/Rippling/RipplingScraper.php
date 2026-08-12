<?php

declare(strict_types=1);

namespace App\Scrapers\Rippling;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\Rippling\Requests\BoardJobsRequest;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Rippling ATS board-API scraper (one company per run).
 *
 * Anonymous GET against the public board-jobs endpoint, JSON response.
 * Paginates via a zero-based `page` index until either the response's
 * `items` comes back empty or its `totalPages` metadata says the walk is
 * done. A hard 10-page safety cap protects against runaway pagination on
 * misconfigured slugs; if the cap is hit while more pages are known (or, if
 * `totalPages` is absent, the last fetched page was full) the run reports a
 * truncation error instead of a clean completion.
 *
 * Conditional-GET headers are forwarded on the first page only, mirroring
 * the Greenhouse scraper - live probing of the endpoint returned no
 * ETag/Last-Modified response headers, so this is forward-compatible
 * plumbing rather than a confirmed 304 contract. Comp fields are not
 * present in the Rippling board payload; the raw item is retained on the
 * role so a future parser pass can revisit it.
 */
final readonly class RipplingScraper implements AtsScraperInterface
{
    private const int PAGE_SIZE = 100;

    private const int MAX_PAGES = 10;

    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::RIPPLING;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $connector = new RipplingConnector;
            $slug = (string) $company->ats_slug;

            $parsed = [];
            $totalPages = null;
            $lastPageItemCount = 0;
            $pageCapHit = true;
            $etag = null;
            $lastModified = null;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $request = new BoardJobsRequest(
                    boardSlug: $slug,
                    page: $page,
                    pageSize: self::PAGE_SIZE,
                    ifNoneMatch: $page === 0 ? $company->etag : null,
                    ifModifiedSince: $page === 0 ? $company->last_modified : null,
                );

                $response = $connector->send($request);
                $status = $response->status();

                if ($page === 0) {
                    $etag = $response->header('ETag') ?: null;
                    $lastModified = $response->header('Last-Modified') ?: null;

                    if ($status === 304) {
                        $company->last_checked_at = CarbonImmutable::now();
                        $company->save();

                        return ScraperRunResult::notModified($company->etag, $company->last_modified);
                    }
                }

                if ($status >= 400) {
                    return new ScraperRunResult(
                        fetched: 0,
                        newCount: 0,
                        closedCount: 0,
                        errors: [[
                            'message' => "Rippling board returned HTTP {$status} for slug [{$company->ats_slug}].",
                            'context' => ['http_status' => $status],
                        ]],
                    );
                }

                $payload = $response->json();
                if (! is_array($payload) || ! array_key_exists('items', $payload) || ! is_array($payload['items'])) {
                    return new ScraperRunResult(
                        fetched: 0,
                        newCount: 0,
                        closedCount: 0,
                        errors: [[
                            'message' => "Rippling board returned a malformed payload (missing or non-array 'items' key) for slug [{$company->ats_slug}].",
                        ]],
                    );
                }

                $items = $payload['items'];
                $totalPages = isset($payload['totalPages']) ? (int) $payload['totalPages'] : $totalPages;

                if ($items === []) {
                    $pageCapHit = false;

                    break;
                }

                foreach ($items as $item) {
                    $parsed[] = $this->parseItem($item);
                }

                $lastPageItemCount = count($items);

                if ($totalPages !== null && ($page + 1) >= $totalPages) {
                    $pageCapHit = false;

                    break;
                }
            }

            $errors = [];
            if ($pageCapHit) {
                if ($totalPages !== null && $totalPages > self::MAX_PAGES) {
                    $errors[] = [
                        'message' => 'Rippling board truncated at '.self::MAX_PAGES." of {$totalPages} pages for slug [{$company->ats_slug}] (hit the ".self::MAX_PAGES.'-page safety cap).',
                    ];
                } elseif ($totalPages === null && $lastPageItemCount >= self::PAGE_SIZE) {
                    $errors[] = [
                        'message' => 'Rippling board truncated at the '.self::MAX_PAGES."-page safety cap for slug [{$company->ats_slug}] (last page was full; more postings may remain).",
                    ];
                }
            }

            $existing = $company->roles()->where('source', AtsType::RIPPLING->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Rippling board returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::RIPPLING, $plan);

            $company->etag = $etag;
            $company->last_modified = $lastModified;
            $company->last_checked_at = CarbonImmutable::now();
            $company->save();

            return new ScraperRunResult(
                fetched: count($parsed),
                newCount: $counts['new'] + $counts['reopened'],
                closedCount: $counts['closed'],
                errors: $errors,
                etag: $etag,
                lastModified: $lastModified,
            );
        } catch (Throwable $e) {
            return new ScraperRunResult(
                fetched: 0,
                newCount: 0,
                closedCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function parseItem(array $item): ParsedRole
    {
        $externalId = (string) ($item['id'] ?? '');
        $title = (string) ($item['name'] ?? '');
        $url = isset($item['url']) ? (string) $item['url'] : null;

        $locations = is_array($item['locations'] ?? null) ? $item['locations'] : [];

        $locationNames = [];
        foreach ($locations as $locationEntry) {
            $name = is_array($locationEntry) ? ($locationEntry['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $locationNames[] = $name;
            }
        }
        $location = $locationNames !== [] ? implode(' / ', $locationNames) : null;

        $isRemote = $location !== null && stripos($location, 'remote') !== false;
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $department = null;
        if (isset($item['department']['name']) && is_string($item['department']['name']) && $item['department']['name'] !== '') {
            $department = $item['department']['name'];
        }

        return new ParsedRole(
            externalId: $externalId,
            title: $title,
            url: $url,
            location: $location,
            isRemote: $isRemote,
            workplaceType: $workplaceType,
            department: $department,
            team: null,
            compMin: null,
            compMax: null,
            compCurrency: null,
            compInterval: null,
            raw: $item,
            postedAt: null,
        );
    }
}
