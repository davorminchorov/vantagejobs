<?php

declare(strict_types=1);

namespace App\Scrapers\Personio;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\Personio\Requests\JobsXmlRequest;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Personio XML-feed scraper (one company per run).
 *
 * SOURCES.md §3.3 — anonymous GET against {slug}.jobs.personio.{de|com}/xml.
 * The .de host is tried first; on HTTP 404 we retry against .com because
 * some customers are provisioned on the .com TLD.
 */
final readonly class PersonioScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::PERSONIO;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.sources.personio.concurrency');
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $slug = (string) $company->ats_slug;
            $host = "https://{$slug}.jobs.personio.de";

            $response = (new PersonioConnector($host))->send(new JobsXmlRequest());

            if ($response->status() === 404) {
                $host = "https://{$slug}.jobs.personio.com";
                $response = (new PersonioConnector($host))->send(new JobsXmlRequest());
            }

            $status = $response->status();
            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Personio feed returned HTTP {$status} for slug [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $body = (string) $response->body();

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($xml === false) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Failed to parse Personio XML for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $parsed = [];
            foreach ($xml->position as $position) {
                $parsed[] = $this->parsePosition($position, $host);
            }

            $existing = $company->roles()->where('source', AtsType::PERSONIO->value)->get();
            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::PERSONIO, $plan);

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

    private function parsePosition(SimpleXMLElement $position, string $host): ParsedRole
    {
        $externalId = trim((string) $position->id);
        $title = trim((string) $position->name);

        $department = trim((string) $position->department);
        if ($department === '') {
            $department = trim((string) $position->recruitingCategory);
        }
        $department = $department !== '' ? $department : null;

        $location = trim((string) $position->office);
        $location = $location !== '' ? $location : null;

        $isRemote = $location !== null && stripos($location, 'remote') !== false;
        $workplaceType = $isRemote ? WorkplaceType::REMOTE : WorkplaceType::UNSPECIFIED;

        $url = $externalId !== '' ? "{$host}/job/{$externalId}" : null;

        $postedAt = null;
        $createdAt = trim((string) $position->createdAt);
        if ($createdAt !== '') {
            try {
                $postedAt = CarbonImmutable::parse($createdAt);
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        /** @var array<string, mixed> $raw */
        $raw = json_decode(json_encode($position) ?: '{}', true) ?: [];

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
            raw: $raw,
            postedAt: $postedAt,
        );
    }
}
