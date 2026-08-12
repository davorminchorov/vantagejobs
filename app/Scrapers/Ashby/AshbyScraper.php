<?php

declare(strict_types=1);

namespace App\Scrapers\Ashby;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Ashby\Requests\JobBoardRequest;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Ashby per-company scraper.
 *
 * SOURCES.md §1.3 — pulls the public job-board JSON for one company, maps
 * each job into a source-agnostic ParsedRole, then hands the batch to
 * RoleDiffer. Honors If-None-Match / If-Modified-Since for cheap polling.
 */
final class AshbyScraper implements AtsScraperInterface
{
    public function __construct(
        private readonly RoleDiffer $differ,
        private readonly ConfigRepository $config,
    ) {}

    public function name(): AtsType
    {
        return AtsType::ASHBY;
    }

    public function concurrency(): int
    {
        return (int) $this->config->get('scrapers.sources.ashby.concurrency', 10);
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $connector = new AshbyConnector(
                userAgent: (string) $this->config->get('scrapers.user_agent'),
                timeout: (float) $this->config->get('scrapers.sources.ashby.timeout', 15),
                connectTimeout: (float) $this->config->get('scrapers.sources.ashby.connect_timeout', 5),
            );

            $request = new JobBoardRequest(
                name: (string) $company->ats_slug,
                ifNoneMatch: $company->etag,
                ifModifiedSince: $company->last_modified,
            );

            $response = $connector->send($request);

            $now = CarbonImmutable::now();

            if ($response->status() === 304) {
                $company->forceFill(['last_checked_at' => $now])->save();

                return ScraperRunResult::notModified(
                    etag: $company->etag,
                    lastModified: $company->last_modified,
                );
            }

            $status = $response->status();
            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Ashby job board returned HTTP {$status} for name [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $body = $response->json();
            if (! is_array($body) || ! array_key_exists('jobs', $body) || ! is_array($body['jobs'])) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Ashby job board returned a malformed payload (missing or non-array 'jobs' key) for name [{$company->ats_slug}].",
                    ]],
                );
            }

            /** @var list<array<string, mixed>> $jobs */
            $jobs = $body['jobs'];

            $parsed = [];
            foreach ($jobs as $job) {
                $parsed[] = $this->parseJob($job);
            }

            $existing = $company->roles()->where('source', AtsType::ASHBY->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Ashby job board returned zero postings for name [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::ASHBY, $plan);

            $etag = $response->header('ETag') ?: null;
            $lastModified = $response->header('Last-Modified') ?: null;

            $company->forceFill([
                'etag' => $etag,
                'last_modified' => $lastModified,
                'last_checked_at' => $now,
            ])->save();

            return new ScraperRunResult(
                fetched: count($parsed),
                newCount: $counts['new'],
                closedCount: $counts['closed'],
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
        $workplaceType = $this->mapWorkplaceType(
            workplaceType: isset($job['workplaceType']) ? (string) $job['workplaceType'] : null,
            isRemote: isset($job['isRemote']) ? (bool) $job['isRemote'] : null,
        );

        $comp = $this->extractCompensation($job['compensation'] ?? null);

        $raw = [];
        if (isset($job['secondaryLocations'])) {
            $raw['secondaryLocations'] = $job['secondaryLocations'];
        }
        if (isset($job['compensation'])) {
            $raw['compensation'] = $job['compensation'];
        }

        return new ParsedRole(
            externalId: (string) $job['id'],
            title: (string) $job['title'],
            url: isset($job['jobUrl']) ? (string) $job['jobUrl'] : null,
            location: isset($job['locationName']) ? (string) $job['locationName'] : null,
            isRemote: isset($job['isRemote']) ? (bool) $job['isRemote'] : null,
            workplaceType: $workplaceType,
            department: isset($job['departmentName']) ? (string) $job['departmentName'] : null,
            team: isset($job['teamName']) ? (string) $job['teamName'] : null,
            compMin: $comp['min'],
            compMax: $comp['max'],
            compCurrency: $comp['currency'],
            compInterval: $comp['interval'],
            raw: $raw === [] ? null : $raw,
            postedAt: isset($job['publishedAt']) ? CarbonImmutable::parse((string) $job['publishedAt']) : null,
        );
    }

    private function mapWorkplaceType(?string $workplaceType, ?bool $isRemote): WorkplaceType
    {
        if ($workplaceType !== null && $workplaceType !== '') {
            return match (strtolower($workplaceType)) {
                'remote' => WorkplaceType::REMOTE,
                'onsite' => WorkplaceType::ON_SITE,
                'hybrid' => WorkplaceType::HYBRID,
                default => WorkplaceType::UNSPECIFIED,
            };
        }

        if ($isRemote === true) {
            return WorkplaceType::REMOTE;
        }

        return WorkplaceType::UNSPECIFIED;
    }

    /**
     * Walks compensation.tiers[] and picks the Salary component from the
     * highest-paying tier (largest maxValue). Equity / Bonus / Commission
     * components are ignored for the structured fields and remain available
     * via ParsedRole::$raw['compensation'].
     *
     * @param  array<string, mixed>|null  $compensation
     * @return array{min: ?int, max: ?int, currency: ?string, interval: ?string}
     */
    private function extractCompensation(?array $compensation): array
    {
        $empty = ['min' => null, 'max' => null, 'currency' => null, 'interval' => null];

        if ($compensation === null || ! isset($compensation['tiers']) || ! is_array($compensation['tiers'])) {
            return $empty;
        }

        $best = null;
        $bestMax = -1;

        foreach ($compensation['tiers'] as $tier) {
            if (! is_array($tier) || ! isset($tier['components']) || ! is_array($tier['components'])) {
                continue;
            }

            foreach ($tier['components'] as $component) {
                if (! is_array($component)) {
                    continue;
                }
                if (($component['compensationType'] ?? null) !== 'Salary') {
                    continue;
                }

                $maxValue = isset($component['maxValue']) ? (int) $component['maxValue'] : 0;
                if ($maxValue > $bestMax) {
                    $bestMax = $maxValue;
                    $best = $component;
                }
            }
        }

        if ($best === null) {
            return $empty;
        }

        return [
            'min' => isset($best['minValue']) ? (int) $best['minValue'] : null,
            'max' => isset($best['maxValue']) ? (int) $best['maxValue'] : null,
            'currency' => isset($best['currencyCode']) ? (string) $best['currencyCode'] : null,
            'interval' => isset($best['interval']) ? (string) $best['interval'] : null,
        ];
    }
}
