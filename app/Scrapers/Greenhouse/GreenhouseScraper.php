<?php

declare(strict_types=1);

namespace App\Scrapers\Greenhouse;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\Greenhouse\Requests\BoardJobsRequest;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Greenhouse board-API scraper (one company per run).
 *
 * SOURCES.md §1.1 — anonymous GET against the board-jobs endpoint, JSON
 * response, conditional-GET honoured. Comp / workplace fields are not
 * reliably present in Greenhouse; the raw payload is retained on the role
 * so the Phase 3 parser can take a second pass.
 */
final readonly class GreenhouseScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::GREENHOUSE;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.sources.greenhouse.concurrency');
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $connector = new GreenhouseConnector();
            $request = new BoardJobsRequest(
                boardToken: (string) $company->ats_slug,
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
                        'message' => "Greenhouse board returned HTTP {$status} for slug [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('jobs', $payload) || ! is_array($payload['jobs'])) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Greenhouse board returned a malformed payload (missing or non-array 'jobs' key) for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $jobs = $payload['jobs'];

            $parsed = [];
            foreach ($jobs as $job) {
                $parsed[] = $this->parseJob($job);
            }

            $existing = $company->roles()->where('source', AtsType::GREENHOUSE->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Greenhouse board returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::GREENHOUSE, $plan);

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
        }

        $isRemote = $location !== null && stripos($location, 'remote') !== false;
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $department = null;
        if (isset($job['departments'][0]['name'])) {
            $department = (string) $job['departments'][0]['name'];
        }

        $postedAt = null;
        if (! empty($job['updated_at'])) {
            try {
                $postedAt = CarbonImmutable::parse((string) $job['updated_at']);
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
            raw: $job,
            postedAt: $postedAt,
        );
    }
}
