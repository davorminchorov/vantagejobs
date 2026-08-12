<?php

declare(strict_types=1);

namespace App\Scrapers\Teamtailor;

use App\Enums\AtsType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Scrapers\ScraperRunResult;
use App\Scrapers\Teamtailor\Requests\JobsRssRequest;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Teamtailor RSS-feed scraper (one company per run).
 *
 * SOURCES.md §3.5 — anonymous GET against the per-customer public RSS feed at
 * https://{company}.teamtailor.com/jobs.rss. The authenticated JSON API needs
 * a per-customer token and is out of scope for v1; the RSS feed exposes the
 * currently open positions in a standard RSS 2.0 shape.
 */
final readonly class TeamtailorScraper implements AtsScraperInterface
{
    public function __construct(private RoleDiffer $differ) {}

    public function name(): AtsType
    {
        return AtsType::TEAMTAILOR;
    }

    public function run(Company $company): ScraperRunResult
    {
        try {
            $slug = (string) $company->ats_slug;
            // ats_host carries the regional subdomain (e.g. {slug}.na.teamtailor.com)
            // when the board was detected on one; otherwise the global host applies.
            $host = is_string($company->ats_host) && $company->ats_host !== ''
                ? $company->ats_host
                : "{$slug}.teamtailor.com";

            $response = (new TeamtailorConnector("https://{$host}"))->send(new JobsRssRequest());

            $status = $response->status();
            if ($status >= 400) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Teamtailor feed returned HTTP {$status} for slug [{$company->ats_slug}].",
                        'context' => ['http_status' => $status],
                    ]],
                );
            }

            $body = (string) $response->body();

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($xml === false || ! isset($xml->channel)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Failed to parse Teamtailor RSS for slug [{$company->ats_slug}].",
                    ]],
                );
            }

            $parsed = [];
            foreach ($xml->channel->item as $item) {
                $parsed[] = $this->parseItem($item);
            }

            $existing = $company->roles()->where('source', AtsType::TEAMTAILOR->value)->get();

            if ($this->differ->wouldMassClose($existing, $parsed)) {
                return new ScraperRunResult(
                    fetched: 0,
                    newCount: 0,
                    closedCount: 0,
                    errors: [[
                        'message' => "Teamtailor feed returned zero postings for slug [{$company->ats_slug}] but open roles exist; refusing to close them.",
                    ]],
                );
            }

            $plan = $this->differ->plan($existing, $parsed);
            $counts = $this->differ->apply($company, AtsType::TEAMTAILOR, $plan);

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

    private function parseItem(SimpleXMLElement $item): ParsedRole
    {
        $title = trim((string) $item->title);
        $link = trim((string) $item->link);

        $guid = trim((string) $item->guid);
        $externalId = $guid !== '' ? $guid : hash('sha1', $link);

        $category = trim((string) $item->category);
        $department = $category !== '' ? $category : null;

        $description = (string) $item->description;

        $postedAt = null;
        $pubDate = trim((string) $item->pubDate);
        if ($pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate);
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $raw = [
            'title' => $title,
            'link' => $link,
            'description' => $description,
            'pubDate' => $pubDate,
            'category' => $category,
        ];

        return new ParsedRole(
            externalId: $externalId,
            title: $title,
            url: $link !== '' ? $link : null,
            location: null,
            isRemote: null,
            workplaceType: WorkplaceType::UNSPECIFIED,
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
