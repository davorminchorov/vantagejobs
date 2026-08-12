<?php

declare(strict_types=1);

namespace App\Scrapers\Recruitee;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\Recruitee\Requests\OffersRequest;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Recruitee offers-API scraper (one company per run).
 *
 * SOURCES.md §3.4 — anonymous GET against the per-tenant offers endpoint, JSON
 * response. No conditional-GET support; the raw payload is retained on each
 * role for the Phase 3 parser.
 */
final readonly class RecruiteeScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::RECRUITEE;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $slug = (string) $company->ats_slug;
            $connector = new RecruiteeConnector("https://{$slug}.recruitee.com");
            $request = new OffersRequest();

            $response = $connector->send($request);
            $status = $response->status();

            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Recruitee offers endpoint returned HTTP {$status} for slug [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('offers', $payload) || ! is_array($payload['offers'])) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Recruitee offers endpoint returned a malformed payload (missing or non-array 'offers' key) for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $offers = $payload['offers'];

            $parsed = [];
            foreach ($offers as $offer) {
                $parsed[] = $this->parseOffer($offer);
            }

            $existing = $company->roles()->where('source', AtsType::RECRUITEE->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Recruitee offers endpoint returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::RECRUITEE, $plan);

            $company->last_checked_at = CarbonImmutable::now();
            $company->save();

            return new ScraperRunResult(
                fetched: count($parsed),
                newCount: $counts['new'] + $counts['reopened'],
                closedCount: $counts['closed'],
                errors: [],
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
     * @param  array<string, mixed>  $offer
     */
    private function parseOffer(array $offer): ParsedRole
    {
        $externalId = (string) ($offer['id'] ?? '');
        $title = (string) ($offer['title'] ?? '');

        $location = null;
        if (! empty($offer['location'])) {
            $location = (string) $offer['location'];
        } else {
            $city = isset($offer['city']) ? (string) $offer['city'] : '';
            $country = isset($offer['country']) ? (string) $offer['country'] : '';
            $joined = trim($city.', '.$country, ", \t\n\r\0\x0B");
            $location = $joined !== '' ? $joined : null;
        }

        $isRemote = ($offer['remote'] ?? null) === true;
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $department = isset($offer['department']) && $offer['department'] !== ''
            ? (string) $offer['department']
            : null;

        $url = null;
        if (! empty($offer['careers_url'])) {
            $url = (string) $offer['careers_url'];
        } elseif (! empty($offer['careers_apply_url'])) {
            $url = (string) $offer['careers_apply_url'];
        }

        $postedAtSource = $offer['published_at'] ?? $offer['created_at'] ?? null;
        $postedAt = null;
        if (! empty($postedAtSource)) {
            try {
                $postedAt = CarbonImmutable::parse((string) $postedAtSource);
            } catch (Throwable) {
                $postedAt = null;
            }
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
            raw: $offer,
            postedAt: $postedAt,
        );
    }
}
