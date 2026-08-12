<?php

declare(strict_types=1);

namespace App\Scrapers\Workday;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use App\Scrapers\Workday\Requests\JobsRequest;
use Carbon\CarbonImmutable;
use Saloon\Http\Response;
use Throwable;

/**
 * Workday CXS scraper (one company per run).
 *
 * SOURCES.md — anonymous POST against the tenant's CXS job-search endpoint.
 * Workday identity is two-part: `Company::$ats_slug` holds "{tenant}/{site}"
 * (e.g. "nvidia/NVIDIAExternalCareerSite"). The numbered pod host
 * (wd1, wd2, wd3, wd5, wd12, ...) is not derivable from the tenant name, so
 * it is probed the first time a company is scraped and then cached on
 * `Company::$ats_host` as a bare hostname (Personio host-caching pattern,
 * generalized from two candidate TLDs to a list of candidate pods) — a 404
 * against a cached host still falls back to a fresh probe, so a stale cache
 * degrades rather than sticks.
 *
 * Paginates via offset (20 rows/page; many tenants ignore a higher `limit`)
 * until `total` is reached, capped at MAX_PAGES as a safety net against a
 * tenant that never converges; a fetch cut short of `total` at the cap is
 * reported as a non-fatal truncation error (SmartRecruiters pattern).
 */
final readonly class WorkdayScraper implements AtsScraperInterface
{
    private const int PAGE_SIZE = 20;

    private const int MAX_PAGES = 100;

    /** @var list<string> */
    private const array HOST_PROBE_PODS = ['wd1', 'wd2', 'wd3', 'wd5', 'wd12'];

    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::WORKDAY;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $slug = (string) $company->ats_slug;
            $parts = array_values(array_filter(
                explode('/', $slug),
                static fn (string $part): bool => $part !== '',
            ));

            if (count($parts) !== 2) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Workday ats_slug [{$slug}] is not in the expected \"tenant/site\" format.",
                    ]],
                );
            }

            [$tenant, $site] = $parts;

            [$host, $firstPage] = $this->resolveHost($company, $tenant, $site);

            if ($host === null || $firstPage === null) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Workday host probing exhausted all pods for tenant [{$tenant}]; no responding host found for site [{$site}].",
                        'context' => ['http_status' => 404],
                    ]],
                );
            }

            [$parsed, $errors, $fatal] = $this->fetchAllPages($host, $tenant, $site, $slug, $firstPage);

            if ($fatal) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: $errors,
                );
            }

            $existing = $company->roles()->where('source', AtsType::WORKDAY->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Workday jobs endpoint returned zero postings for slug [{$slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::WORKDAY, $plan);

            $company->last_checked_at = CarbonImmutable::now();
            $company->ats_host = $host;
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
     * Resolves the pod host to use for this company: the cached host if one
     * is set and doesn't signal a pod mismatch, otherwise a fresh probe
     * across HOST_PROBE_PODS. Returns the resolved bare hostname alongside
     * the already-fetched first page (offset 0) so callers don't re-fetch it,
     * or [null, null] if every candidate misses (or fails outright).
     *
     * Live probing (NVIDIA, 2026-08-12): a pod that doesn't host the tenant
     * answers 422 (not 404), and some pods don't resolve at all (connection
     * failure), so a probe counts as a hit only on a 2xx/3xx response and a
     * cached host is re-probed on 404 *or* 422. Other statuses (e.g. a
     * transient 5xx) on a cached host are returned as-is so the run reports
     * the real upstream failure instead of a bogus slug-not-found.
     *
     * @return array{0: ?string, 1: ?Response}
     */
    private function resolveHost(Company $company, string $tenant, string $site): array
    {
        $cachedHost = is_string($company->ats_host) && $company->ats_host !== '' ? $company->ats_host : null;

        if ($cachedHost !== null) {
            $response = (new WorkdayConnector("https://{$cachedHost}"))->send(new JobsRequest($tenant, $site, self::PAGE_SIZE, 0));

            if (! in_array($response->status(), [404, 422], true)) {
                return [$cachedHost, $response];
            }
        }

        foreach (self::HOST_PROBE_PODS as $pod) {
            $candidateHost = "{$tenant}.{$pod}.myworkdayjobs.com";

            try {
                $response = (new WorkdayConnector("https://{$candidateHost}"))->send(new JobsRequest($tenant, $site, self::PAGE_SIZE, 0));
            } catch (Throwable) {
                continue;
            }

            if ($response->status() < 400) {
                return [$candidateHost, $response];
            }
        }

        return [null, null];
    }

    /**
     * Walks pages starting from the already-fetched first page, accumulating
     * ParsedRole rows until `jobPostings` comes back empty, `total` is
     * reached, or MAX_PAGES is hit (reported as a non-fatal truncation
     * error via $errors rather than aborting the run).
     *
     * @return array{0: list<ParsedRole>, 1: list<array{message: string, context?: array<string, mixed>}>, 2: bool} [parsed, errors, fatal]
     *                                                                                                              fatal is true only when the run must abort with $errors as the sole result.
     */
    private function fetchAllPages(string $host, string $tenant, string $site, string $slug, Response $firstPage): array
    {
        $connector = new WorkdayConnector("https://{$host}");

        $parsed = [];
        $total = null;
        $offset = 0;
        $pageCapHit = true;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $page === 0 ? $firstPage : $connector->send(new JobsRequest($tenant, $site, self::PAGE_SIZE, $offset));
            $status = $response->status();

            if ($status >= 400) {
                return [[], [[
                    'message' => "Workday jobs endpoint returned HTTP {$status} for slug [{$slug}].",
                    'context' => ['http_status' => $status],
                ]], true];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('jobPostings', $payload) || ! is_array($payload['jobPostings'])) {
                return [[], [[
                    'message' => "Workday jobs endpoint returned a malformed payload (missing or non-array 'jobPostings' key) for slug [{$slug}].",
                ]], true];
            }

            $jobPostings = $payload['jobPostings'];

            // Live behavior (NVIDIA, 2026-08-12): only the offset-0 page
            // carries the real `total`; later pages report `total: 0` while
            // still returning postings. A zero total is therefore only
            // trusted before any postings have been seen (a genuinely empty
            // board), never as a stop signal mid-walk.
            if (isset($payload['total'])) {
                $pageTotal = (int) $payload['total'];
                if ($pageTotal > 0 || $total === null) {
                    $total = $pageTotal;
                }
            }

            if ($jobPostings === []) {
                $pageCapHit = false;

                break;
            }

            foreach ($jobPostings as $posting) {
                $parsed[] = $this->parsePosting($posting, $host, $site);
            }

            $offset += count($jobPostings);

            if ($total !== null && $offset >= $total) {
                $pageCapHit = false;

                break;
            }
        }

        $errors = [];
        if ($pageCapHit && $total !== null && $offset < $total) {
            $errors[] = [
                'message' => "Workday jobs truncated at {$offset} of {$total} for slug [{$slug}] (hit the ".self::MAX_PAGES.'-page safety cap).',
            ];
        }

        return [$parsed, $errors, false];
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function parsePosting(array $posting, string $host, string $site): ParsedRole
    {
        $title = (string) ($posting['title'] ?? '');
        $externalPath = (string) ($posting['externalPath'] ?? '');

        $bulletFields = is_array($posting['bulletFields'] ?? null) ? $posting['bulletFields'] : [];
        $requisitionId = isset($bulletFields[0]) && is_string($bulletFields[0]) && $bulletFields[0] !== ''
            ? $bulletFields[0]
            : null;

        $externalId = $requisitionId ?? $externalPath;

        $location = isset($posting['locationsText']) && is_string($posting['locationsText']) && $posting['locationsText'] !== ''
            ? $posting['locationsText']
            : null;

        $isRemote = $location !== null && stripos($location, 'remote') !== false;
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $url = $externalPath !== '' ? "https://{$host}/en-US/{$site}{$externalPath}" : null;

        $postedAt = $this->parsePostedOn(isset($posting['postedOn']) ? (string) $posting['postedOn'] : null);

        return new ParsedRole(
            externalId: $externalId,
            title: $title,
            url: $url,
            location: $location,
            isRemote: $isRemote,
            workplaceType: $workplaceType,
            department: null,
            team: null,
            compMin: null,
            compMax: null,
            compCurrency: null,
            compInterval: null,
            raw: $posting,
            postedAt: $postedAt,
        );
    }

    /**
     * Workday reports posting age as a relative phrase rather than a date:
     * "Posted Today", "Posted Yesterday", "Posted N Days Ago", and
     * "Posted 30+ Days Ago" once a listing is old enough that Workday stops
     * tracking the exact day. That last form (and anything else unrecognized)
     * has no reliable date to recover, so it maps to null rather than a
     * guess.
     */
    private function parsePostedOn(?string $postedOn): ?CarbonImmutable
    {
        if ($postedOn === null) {
            return null;
        }

        $postedOn = trim($postedOn);

        if ($postedOn === 'Posted Today') {
            return CarbonImmutable::now();
        }

        if ($postedOn === 'Posted Yesterday') {
            return CarbonImmutable::now()->subDay();
        }

        if (preg_match('/^Posted (\d+) Days Ago$/', $postedOn, $matches) === 1) {
            return CarbonImmutable::now()->subDays((int) $matches[1]);
        }

        return null;
    }
}
