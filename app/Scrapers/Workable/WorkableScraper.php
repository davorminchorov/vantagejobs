<?php

declare(strict_types=1);

namespace App\Scrapers\Workable;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use App\Scrapers\Workable\Requests\WidgetAccountRequest;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Workable widget-API scraper (one company per run).
 *
 * SOURCES.md §3.1 — anonymous GET against the public widget account endpoint.
 * The widget response carries workplace + telecommuting hints, so the scraper
 * maps those directly into WorkplaceType / is_remote without waiting on the
 * Phase 3 parser.
 */
final readonly class WorkableScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::WORKABLE;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.sources.workable.concurrency');
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $connector = new WorkableConnector();
            $request = new WidgetAccountRequest(subdomain: (string) $company->ats_slug);

            $response = $connector->send($request);
            $status = $response->status();

            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Workable widget returned HTTP {$status} for slug [{$company->ats_slug}].",
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
                        'message' => "Workable widget returned a malformed payload (missing or non-array 'jobs' key) for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $jobs = $payload['jobs'];

            $parsed = [];
            foreach ($jobs as $job) {
                $parsed[] = $this->parseJob($job);
            }

            $existing = $company->roles()->where('source', AtsType::WORKABLE->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Workable widget returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::WORKABLE, $plan);

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
     * @param  array<string, mixed>  $job
     */
    private function parseJob(array $job): ParsedRole
    {
        $externalId = (string) ($job['shortcode'] ?? $job['id'] ?? '');
        $title = (string) ($job['title'] ?? '');
        $url = isset($job['url']) ? (string) $job['url'] : (isset($job['application_url']) ? (string) $job['application_url'] : null);

        $locationData = is_array($job['location'] ?? null) ? $job['location'] : [];

        $locationParts = [];
        foreach (['city', 'region', 'country'] as $key) {
            $value = $locationData[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $locationParts[] = $value;
            }
        }
        $location = $locationParts !== [] ? implode(', ', $locationParts) : null;

        $telecommuting = ($locationData['telecommuting'] ?? null) === true;
        $workplaceRaw = isset($locationData['workplace']) ? (string) $locationData['workplace'] : null;

        $workplaceType = match ($workplaceRaw) {
            'on_site' => WorkplaceType::ON_SITE,
            'remote' => WorkplaceType::REMOTE,
            'hybrid' => WorkplaceType::HYBRID,
            default => $telecommuting ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED,
        };

        $isRemote = $workplaceType === WorkplaceType::REMOTE
            || $telecommuting
            || ($location !== null && stripos($location, 'remote') !== false);

        $department = isset($job['department']) && is_string($job['department']) && $job['department'] !== ''
            ? $job['department']
            : null;

        $postedAt = null;
        if (! empty($job['created_at'])) {
            try {
                $postedAt = CarbonImmutable::parse((string) $job['created_at']);
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
