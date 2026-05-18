<?php

declare(strict_types=1);

namespace App\Scrapers\Lever;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\Lever\Requests\PostingsRequest;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Lever ATS scraper.
 *
 * Fetches /v0/postings/{site}?mode=json (a bare JSON array of postings),
 * translates each row into ParsedRole, and hands the list to RoleDiffer.
 * Conditional GET via If-None-Match / If-Modified-Since short-circuits to
 * `ScraperRunResult::notModified()` on a 304.
 *
 * Lever quirks worth remembering:
 *   - `createdAt` is a Unix-millisecond timestamp (not seconds).
 *   - `workplaceType` uses hyphenated values: `on-site`, not `on_site`.
 *   - `salaryRange` is optional; when present it always has currency, min, max
 *     and an `interval` string (e.g. `per-year`).
 */
final readonly class LeverScraper implements AtsScraperInterface
{
    public function __construct(
        private RoleDiffer $differ,
        private LeverConnector $connector,
        private ConfigRepository $config,
    ) {}

    public function name(): AtsType
    {
        return AtsType::LEVER;
    }

    public function concurrency(): int
    {
        return (int) $this->config->get('scrapers.sources.lever.concurrency', 10);
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $request = new PostingsRequest(
                site: (string) $company->ats_slug,
                ifNoneMatch: $company->etag,
                ifModifiedSince: $company->last_modified,
            );

            $response = $this->connector->send($request);

            if ($response->status() === 304) {
                $company->last_checked_at = CarbonImmutable::now();
                $company->save();

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
                        'message' => "Lever postings returned HTTP {$status} for site [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            /** @var array<int, array<string, mixed>> $body */
            $body = $response->json();

            if (! is_array($body)) {
                $body = [];
            }

            $parsed = [];
            foreach ($body as $posting) {
                if (! is_array($posting)) {
                    continue;
                }
                $parsed[] = $this->toParsedRole($posting);
            }

            $plan = $this->differ->plan($company->roles()->where('source', AtsType::LEVER->value)->get(), $parsed);
            $counts = $this->differ->apply($company, AtsType::LEVER, $plan);

            $etag = $response->header('ETag') ?: null;
            $lastModified = $response->header('Last-Modified') ?: null;

            $company->etag = $etag;
            $company->last_modified = $lastModified;
            $company->last_checked_at = CarbonImmutable::now();
            $company->save();

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
     * @param  array<string, mixed>  $posting
     */
    private function toParsedRole(array $posting): ParsedRole
    {
        $categories = is_array($posting['categories'] ?? null) ? $posting['categories'] : [];
        $salary = is_array($posting['salaryRange'] ?? null) ? $posting['salaryRange'] : null;

        $workplaceType = $this->workplaceType($posting['workplaceType'] ?? null);

        return new ParsedRole(
            externalId: (string) ($posting['id'] ?? ''),
            title: (string) ($posting['text'] ?? ''),
            url: isset($posting['hostedUrl']) ? (string) $posting['hostedUrl'] : null,
            location: isset($categories['location']) ? (string) $categories['location'] : null,
            isRemote: $workplaceType === WorkplaceType::REMOTE,
            workplaceType: $workplaceType,
            department: isset($categories['department']) ? (string) $categories['department'] : null,
            team: isset($categories['team']) ? (string) $categories['team'] : null,
            compMin: $salary !== null && isset($salary['min']) ? (int) $salary['min'] : null,
            compMax: $salary !== null && isset($salary['max']) ? (int) $salary['max'] : null,
            compCurrency: $salary !== null && isset($salary['currency']) ? (string) $salary['currency'] : null,
            compInterval: $salary !== null && isset($salary['interval']) ? (string) $salary['interval'] : null,
            raw: $posting,
            postedAt: isset($posting['createdAt']) ? CarbonImmutable::createFromTimestampMs((int) $posting['createdAt']) : null,
        );
    }

    private function workplaceType(mixed $value): WorkplaceType
    {
        if (! is_string($value)) {
            return WorkplaceType::UNSPECIFIED;
        }

        return match ($value) {
            'remote' => WorkplaceType::REMOTE,
            'on-site' => WorkplaceType::ON_SITE,
            'hybrid' => WorkplaceType::HYBRID,
            default => WorkplaceType::UNSPECIFIED,
        };
    }
}
