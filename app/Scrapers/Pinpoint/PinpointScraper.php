<?php

declare(strict_types=1);

namespace App\Scrapers\Pinpoint;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\Pinpoint\Requests\PostingsRequest;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pinpoint public-postings scraper (one company per run).
 *
 * Per https://developers.pinpointhq.com/docs/jobs-json-endpoint — anonymous
 * GET against https://{slug}.pinpointhq.com/postings.json, JSON response,
 * conditional-GET honoured via ETag. A live probe against
 * workwithus.pinpointhq.com confirmed the endpoint returns every open
 * posting in a single `{"data": [...]}` response — no page/cursor
 * parameters are documented or present, so this scraper (unlike
 * SmartRecruiters) makes exactly one request per run. Unlike Greenhouse,
 * compensation is reliably structured on the payload
 * (compensation_minimum/maximum/currency/frequency), gated by
 * compensation_visible.
 */
final readonly class PinpointScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::PINPOINT;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $slug = (string) $company->ats_slug;
            $connector = new PinpointConnector(baseUrl: "https://{$slug}.pinpointhq.com");
            $request = new PostingsRequest(
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
                        'message' => "Pinpoint postings returned HTTP {$status} for slug [{$slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Pinpoint postings returned a malformed payload (missing or non-array 'data' key) for slug [{$slug}].",
                    ]],
                );
            }

            $jobs = $payload['data'];

            $parsed = [];
            foreach ($jobs as $job) {
                $parsed[] = $this->parseJob($job);
            }

            $existing = $company->roles()->where('source', AtsType::PINPOINT->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Pinpoint postings returned zero postings for slug [{$slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::PINPOINT, $plan);

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
        $url = isset($job['url']) ? (string) $job['url'] : null;

        $location = null;
        if (isset($job['location']['name']) && is_string($job['location']['name']) && $job['location']['name'] !== '') {
            $location = $job['location']['name'];
        } elseif (isset($job['location']['city']) && is_string($job['location']['city']) && $job['location']['city'] !== '') {
            $location = $job['location']['city'];
        }

        $workplaceTypeRaw = is_string($job['workplace_type'] ?? null) ? $job['workplace_type'] : null;
        $workplaceType = match ($workplaceTypeRaw) {
            'remote' => WorkplaceType::REMOTE,
            'hybrid' => WorkplaceType::HYBRID,
            'onsite' => WorkplaceType::ON_SITE,
            default => WorkplaceType::UNSPECIFIED,
        };

        // Pinpoint's workplace_type is authoritative when present. Only fall
        // back to a Greenhouse-style location string match when the value is
        // missing or something this scraper doesn't recognize yet.
        $isRemote = match ($workplaceType) {
            WorkplaceType::REMOTE => true,
            WorkplaceType::HYBRID, WorkplaceType::ON_SITE => false,
            WorkplaceType::UNSPECIFIED => $location !== null && stripos($location, 'remote') !== false,
        };

        $department = null;
        if (isset($job['job']['department']['name']) && is_string($job['job']['department']['name']) && $job['job']['department']['name'] !== '') {
            $department = $job['job']['department']['name'];
        }

        $compVisible = ($job['compensation_visible'] ?? false) === true;

        $compMin = $compVisible && isset($job['compensation_minimum']) && is_numeric($job['compensation_minimum'])
            ? (int) $job['compensation_minimum']
            : null;

        $compMax = $compVisible && isset($job['compensation_maximum']) && is_numeric($job['compensation_maximum'])
            ? (int) $job['compensation_maximum']
            : null;

        $compCurrency = $compVisible && isset($job['compensation_currency']) && is_string($job['compensation_currency']) && $job['compensation_currency'] !== ''
            ? $job['compensation_currency']
            : null;

        $compInterval = $compVisible && isset($job['compensation_frequency']) && is_string($job['compensation_frequency']) && $job['compensation_frequency'] !== ''
            ? $job['compensation_frequency']
            : null;

        return new ParsedRole(
            externalId: $externalId,
            title: $title,
            url: $url,
            location: $location,
            isRemote: $isRemote,
            workplaceType: $workplaceType,
            department: $department,
            team: null,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            compInterval: $compInterval,
            raw: $job,
            postedAt: null,
        );
    }
}
