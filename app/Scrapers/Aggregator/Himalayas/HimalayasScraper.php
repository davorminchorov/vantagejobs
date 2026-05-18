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
 *   - The structured JSON search endpoint returns `{ jobs: [...], total }`.
 *   - Rate limiting is non-numeric; their cache refreshes every 24h so daily
 *     polling is plenty. Concurrency stays at 1.
 *   - Headline feature: `timezoneRestrictions[]` (e.g. ["UTC+01:00",
 *     "UTC+02:00"]) — critical for the CET-based user. The RemoteExtractor
 *     reads it directly from `structured` and emits an EXPLICIT, 0.9-confidence
 *     remote field.
 */
final readonly class HimalayasScraper implements AggregatorScraperInterface
{
    public function __construct(
        private HimalayasContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::HIMALAYAS;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.himalayas.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new HimalayasConnector())->send(new JobsSearchRequest());
            $payload = $response->json();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }
            $externalId = isset($job['guid']) && is_string($job['guid']) ? $job['guid'] : '';
            if ($externalId === '') {
                continue;
            }

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
                    'context' => ['guid' => $externalId],
                ];
            }
        }

        $this->persister->capRun($createdIds);

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: count($createdIds),
            errors: $errors,
        );
    }
}
