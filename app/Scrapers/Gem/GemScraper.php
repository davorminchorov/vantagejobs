<?php

declare(strict_types=1);

namespace App\Scrapers\Gem;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\Gem\Requests\JobPostsRequest;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Gem job-board-API scraper (one company per run).
 *
 * GET https://api.gem.com/job_board/v0/{slug}/job_posts/ — anonymous GET,
 * conditional-GET honoured. Unlike Greenhouse, the response is a bare JSON
 * array of job posts rather than an object wrapping a "jobs" key, and each
 * job carries a structured `location_type` field (`remote` / `hybrid` /
 * `in_office`) that Greenhouse does not expose, so workplace type is
 * derived from that field rather than sniffed from the location string
 * alone. Comp is not exposed as structured data (only ever inline in the
 * HTML `content`), so comp fields stay null and the raw payload is retained
 * on the role for a later parser pass.
 */
final readonly class GemScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::GEM;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $connector = new GemConnector;
            $request = new JobPostsRequest(
                vanityUrlPath: (string) $company->ats_slug,
                ifNoneMatch: $company->etag,
                ifModifiedSince: $company->last_modified,
            );

            $response = $connector->send($request);
            $status = $response->status();

            if ($status === 304) {
                $company->last_checked_at = CarbonImmutable::now();
                $company->save();

                return ScraperRunResult::notModified($company->etag, $company->last_modified);
            }

            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Gem job board returned HTTP {$status} for slug [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_is_list($payload)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Gem job board returned a malformed payload (expected a JSON array of job posts) for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $jobs = $payload;

            $parsed = [];
            foreach ($jobs as $job) {
                $parsed[] = $this->parseJob($job);
            }

            $existing = $company->roles()->where('source', AtsType::GEM->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Gem job board returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::GEM, $plan);

            $etag = $response->header('ETag') ?: null;
            $lastModified = $response->header('Last-Modified') ?: null;

            $company->etag = $etag;
            $company->last_modified = $lastModified;
            $company->last_checked_at = CarbonImmutable::now();
            $company->save();

            return new ScraperRunResult(
                fetched: count($parsed),
                newCount: $counts['new'] + $counts['reopened'],
                closedCount: $counts['closed'],
                errors: [],
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
     * @param  array<string, mixed>  $job
     */
    private function parseJob(array $job): ParsedRole
    {
        $externalId = (string) ($job['id'] ?? '');
        $title = (string) ($job['title'] ?? '');
        $url = isset($job['absolute_url']) ? (string) $job['absolute_url'] : null;

        $location = null;
        if (isset($job['location']['name'])) {
            $location = (string) $job['location']['name'];
        } elseif (isset($job['offices'][0]['location']['name'])) {
            $location = (string) $job['offices'][0]['location']['name'];
        } elseif (isset($job['offices'][0]['name'])) {
            $location = (string) $job['offices'][0]['name'];
        }

        $locationType = isset($job['location_type']) ? (string) $job['location_type'] : null;
        $isRemote = $locationType === 'remote' || ($location !== null && stripos($location, 'remote') !== false);
        $workplaceType = match (true) {
            $isRemote => WorkplaceType::REMOTE,
            $locationType === 'hybrid' => WorkplaceType::HYBRID,
            $locationType === 'in_office' => WorkplaceType::ON_SITE,
            default => WorkplaceType::UNSPECIFIED,
        };

        $department = null;
        if (isset($job['departments'][0]['name'])) {
            $department = (string) $job['departments'][0]['name'];
        }

        $postedAt = null;
        foreach (['updated_at', 'created_at'] as $field) {
            if (! empty($job[$field])) {
                try {
                    $postedAt = CarbonImmutable::parse((string) $job[$field]);
                    break;
                } catch (Throwable) {
                    $postedAt = null;
                }
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
            raw: $job,
            postedAt: $postedAt,
        );
    }
}
