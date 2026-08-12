<?php

declare(strict_types=1);

namespace App\Scrapers\SmartRecruiters;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use App\Scrapers\SmartRecruiters\Requests\CompanyPostingsRequest;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * SmartRecruiters public-postings scraper (one company per run).
 *
 * SOURCES.md §3.2 — anonymous GET against the public postings endpoint.
 * Paginates via offset until either the response `content` is empty or the
 * cumulative offset reaches `totalFound`. A hard 10-page safety cap protects
 * against runaway pagination on misconfigured slugs; if the cap is hit while
 * `totalFound` indicates more postings remain, the run reports a truncation
 * error instead of a clean `completed` status.
 */
final readonly class SmartRecruitersScraper implements AtsScraperInterface
{
    private const int PAGE_SIZE = 100;

    private const int MAX_PAGES = 10;

    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::SMARTRECRUITERS;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $connector = new SmartRecruitersConnector();
            $identifier = (string) $company->ats_slug;

            $parsed = [];
            $offset = 0;
            $totalFound = null;
            $pageCapHit = true;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $request = new CompanyPostingsRequest(
                    companyIdentifier: $identifier,
                    offset: $offset,
                    limit: self::PAGE_SIZE,
                );

                $response = $connector->send($request);
                $status = $response->status();

                if ($status >= 400) {
                    return new ScraperRunResult(
                        fetched: 0,
                        newCount: 0,
                        closedCount: 0,
                        errors: [[
                            'message' => "SmartRecruiters postings returned HTTP {$status} for slug [{$company->ats_slug}].",
                            'context' => ['http_status' => $status],
                        ]],
                    );
                }

                $payload = $response->json();
                if (! is_array($payload) || ! array_key_exists('content', $payload) || ! is_array($payload['content'])) {
                    return new ScraperRunResult(
                        fetched: 0,
                        newCount: 0,
                        closedCount: 0,
                        errors: [[
                            'message' => "SmartRecruiters postings returned a malformed payload (missing or non-array 'content' key) for slug [{$company->ats_slug}].",
                        ]],
                    );
                }

                $content = $payload['content'];
                $totalFound = isset($payload['totalFound']) ? (int) $payload['totalFound'] : $totalFound;

                if ($content === []) {
                    $pageCapHit = false;

                    break;
                }

                foreach ($content as $posting) {
                    $parsed[] = $this->parsePosting($posting, $identifier);
                }

                $offset += count($content);

                if ($totalFound !== null && $offset >= $totalFound) {
                    $pageCapHit = false;

                    break;
                }
            }

            $errors = [];
            if ($pageCapHit && $totalFound !== null && $offset < $totalFound) {
                $errors[] = [
                    'message' => "SmartRecruiters postings truncated at {$offset} of {$totalFound} for slug [{$company->ats_slug}] (hit the ".self::MAX_PAGES.'-page safety cap).',
                ];
            }

            $existing = $company->roles()->where('source', AtsType::SMARTRECRUITERS->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "SmartRecruiters postings returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::SMARTRECRUITERS, $plan);

            $company->last_checked_at = CarbonImmutable::now();
            $company->save();

            return new ScraperRunResult(
                fetched: count($parsed),
                newCount: $counts['new'] + $counts['reopened'],
                closedCount: $counts['closed'],
                errors: $errors,
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
     * @param  array<string, mixed>  $posting
     */
    private function parsePosting(array $posting, string $companyIdentifier): ParsedRole
    {
        $externalId = (string) ($posting['id'] ?? '');
        $title = (string) ($posting['name'] ?? '');

        $locationData = is_array($posting['location'] ?? null) ? $posting['location'] : [];

        $locationParts = [];
        foreach (['city', 'region', 'country'] as $key) {
            $value = $locationData[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $locationParts[] = $value;
            }
        }
        $location = $locationParts !== [] ? implode(', ', $locationParts) : null;

        $isRemote = ($locationData['remote'] ?? null) === true;
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $department = null;
        if (isset($posting['department']['label']) && is_string($posting['department']['label']) && $posting['department']['label'] !== '') {
            $department = $posting['department']['label'];
        } elseif (isset($posting['function']['label']) && is_string($posting['function']['label']) && $posting['function']['label'] !== '') {
            $department = $posting['function']['label'];
        }

        $url = $externalId !== ''
            ? "https://jobs.smartrecruiters.com/{$companyIdentifier}/{$externalId}"
            : null;

        $postedAt = null;
        if (! empty($posting['releasedDate'])) {
            try {
                $postedAt = CarbonImmutable::parse((string) $posting['releasedDate']);
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
            raw: $posting,
            postedAt: $postedAt,
        );
    }
}
