<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Himalayas;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\Himalayas\Requests\JobsSearchRequest;
use Throwable;

/**
 * Himalayas aggregator scraper.
 *
 * Per SOURCES.md §2.3:
 *   - The structured JSON endpoint returns `{ jobs: [...], offset, limit,
 *     totalCount }` with 20 jobs per page (page size is fixed server-side).
 *   - Rate limiting is non-numeric; their cache refreshes every 24h so daily
 *     polling is plenty. Concurrency stays at 1.
 *   - Headline feature: `timezoneRestrictions[]` (e.g. ["UTC+01:00",
 *     "UTC+02:00"]) — critical for the CET-based user. The RemoteExtractor
 *     reads it directly from `structured` and emits an EXPLICIT, 0.9-confidence
 *     remote field.
 */
final readonly class HimalayasScraper implements AggregatorScraperInterface
{
    private const PAGE_SIZE = 20;

    /** Safety cap on pagination so a misbehaving upstream can't loop forever. */
    private const MAX_PAGES = 50;

    public function __construct(
        private HimalayasContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::HIMALAYAS;
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        $connector = new HimalayasConnector();

        $fetched = 0;
        /** @var list<int> $createdIds */
        $createdIds = [];
        $errors = [];
        /** @var array<string, true> $seenGuids */
        $seenGuids = [];
        $lastTotalCount = null;
        // Stays true only if the loop runs out of MAX_PAGES iterations without
        // ever reaching a legitimate stopping point (empty page, totalCount
        // satisfied, or a fetch error already recorded above) - i.e. the API
        // genuinely has more data than the safety cap allows us to fetch.
        $pagesExhausted = true;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $offset = $page * self::PAGE_SIZE;

            try {
                $response = $connector->send(new JobsSearchRequest($offset));
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['offset' => $offset],
                ];
                $pagesExhausted = false;

                break;
            }

            if ($response->status() >= 400) {
                $errors[] = [
                    'message' => "Himalayas API returned HTTP {$response->status()}.",
                    'context' => ['http_status' => $response->status(), 'offset' => $offset],
                ];
                $pagesExhausted = false;

                break;
            }

            $payload = $response->json();

            $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];
            if ($jobs === []) {
                $pagesExhausted = false;

                break;
            }

            $totalCount = is_int($payload['totalCount'] ?? null) ? (int) $payload['totalCount'] : null;
            $lastTotalCount = $totalCount ?? $lastTotalCount;

            foreach ($jobs as $job) {
                if (! is_array($job)) {
                    continue;
                }
                $externalId = isset($job['guid']) && is_string($job['guid']) ? $job['guid'] : '';
                if ($externalId === '' || isset($seenGuids[$externalId])) {
                    continue;
                }
                $seenGuids[$externalId] = true;

                $fetched++;

                try {
                    $context = $this->contextBuilder->build($job);
                    $id = $this->persister->upsert($source, $context, $job, $externalId);
                    if ($id !== null) {
                        $createdIds[] = $id;
                    }
                } catch (Throwable $e) {
                    $errors[] = [
                        'message' => $e->getMessage(),
                        'context' => ['guid' => $externalId, 'offset' => $offset],
                    ];
                }
            }

            if ($totalCount !== null && $fetched >= $totalCount) {
                $pagesExhausted = false;

                break;
            }
        }

        if ($pagesExhausted && $lastTotalCount !== null && $fetched < $lastTotalCount) {
            $errors[] = [
                'message' => "Himalayas pagination truncated at MAX_PAGES ({$fetched} of {$lastTotalCount} fetched).",
                'context' => ['max_pages' => self::MAX_PAGES, 'fetched' => $fetched, 'total_count' => $lastTotalCount],
            ];
        }

        $this->persister->capRun($createdIds);

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: count($createdIds),
            errors: $errors,
        );
    }
}
