<?php

declare(strict_types=1);

namespace App\Scrapers\BambooHr;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\BambooHr\Requests\CareersListRequest;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * BambooHR careers-feed scraper (one company per run).
 *
 * Anonymous GET against https://{slug}.bamboohr.com/careers/list, JSON
 * response, single page (no pagination), conditional-GET honoured. The
 * list payload carries no description or posted-date field, so those are
 * left null; the raw payload is retained on the role so a later parser
 * pass can enrich it.
 *
 * An invalid subdomain 302-redirects to the www.bamboohr.com marketing
 * site rather than returning a 404. BambooHrConnector disables redirect
 * following, so that response arrives here as a bare 3xx status, which is
 * treated as slug-not-found and reported as context.http_status = 404: the
 * ATS-health redetect mechanism in ScrapeOrchestrator keys on 404 to decide
 * when a board has gone missing, and a redirect to the marketing site means
 * the board never existed at this slug. Genuine >=400 responses keep their
 * own status code in http_status.
 */
final readonly class BambooHrScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::BAMBOOHR;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $slug = (string) $company->ats_slug;
            $baseUrl = "https://{$slug}.bamboohr.com";

            $connector = new BambooHrConnector($baseUrl);
            $request = new CareersListRequest(
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

            if ($status >= 300 && $status < 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "BambooHR redirected away from the careers list for slug [{$company->ats_slug}]; the board does not exist at this subdomain.",
                        'context' => ['http_status' => 404],
                    ]],
                );
            }

            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "BambooHR careers list returned HTTP {$status} for slug [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('result', $payload) || ! is_array($payload['result'])) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "BambooHR careers list returned a malformed payload (missing or non-array 'result' key) for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $jobs = $payload['result'];

            $parsed = [];
            foreach ($jobs as $job) {
                $parsed[] = $this->parseJob($job, $slug);
            }

            $existing = $company->roles()->where('source', AtsType::BAMBOOHR->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "BambooHR careers list returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::BAMBOOHR, $plan);

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
    private function parseJob(array $job, string $slug): ParsedRole
    {
        $externalId = (string) ($job['id'] ?? '');
        $title = (string) ($job['jobOpeningName'] ?? '');
        $url = $externalId !== '' ? "https://{$slug}.bamboohr.com/careers/{$externalId}" : null;

        $location = $this->resolveLocation($job);

        $isRemote = $this->resolveIsRemote($job, $location);
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $department = null;
        if (! empty($job['departmentLabel'])) {
            $department = (string) $job['departmentLabel'];
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
            postedAt: null,
        );
    }

    /**
     * Joins location.city + location.state (comma-separated, empty parts
     * dropped). Falls back to atsLocation's city/state/country when the
     * primary location carries no city or state at all.
     *
     * @param  array<string, mixed>  $job
     */
    private function resolveLocation(array $job): ?string
    {
        $primary = is_array($job['location'] ?? null) ? $job['location'] : [];
        $parts = array_filter([
            (string) ($primary['city'] ?? ''),
            (string) ($primary['state'] ?? ''),
        ], static fn (string $part): bool => $part !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $ats = is_array($job['atsLocation'] ?? null) ? $job['atsLocation'] : [];
        $atsParts = array_filter([
            (string) ($ats['city'] ?? ''),
            (string) ($ats['state'] ?? ($ats['province'] ?? '')),
            (string) ($ats['country'] ?? ''),
        ], static fn (string $part): bool => $part !== '');

        return $atsParts !== [] ? implode(', ', $atsParts) : null;
    }

    /**
     * Prefers the payload's own isRemote flag when it's a genuine boolean.
     * Live probing (ashteadtechnology.bamboohr.com/careers/list, 2026-08-12)
     * never returned a non-null isRemote, but did show locationType "1"
     * exclusively on postings with no city set and only a country in
     * atsLocation (e.g. "Offshore ROV Tooling Technician"), against
     * locationType "0" on every posting with a city, so locationType is
     * treated as the next-best remote signal, with a "remote" substring
     * match on the resolved location text as the last resort.
     *
     * @param  array<string, mixed>  $job
     */
    private function resolveIsRemote(array $job, ?string $location): bool
    {
        $isRemoteField = $job['isRemote'] ?? null;
        if (is_bool($isRemoteField)) {
            return $isRemoteField;
        }

        $locationType = isset($job['locationType']) ? (string) $job['locationType'] : null;
        if ($locationType === '1') {
            return true;
        }

        return $location !== null && stripos($location, 'remote') !== false;
    }
}
