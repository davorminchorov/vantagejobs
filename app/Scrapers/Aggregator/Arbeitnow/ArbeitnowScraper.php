<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Arbeitnow\Requests\JobBoardRequest;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use Throwable;

/**
 * Arbeitnow job-board JSON scraper.
 *
 *   - Single GET against /api/job-board-api returns the latest page of
 *     `data[]` records.
 *   - external_id = `slug` (canonical, present in the URL).
 *   - No published rate limit; daily cadence with concurrency 1.
 */
final readonly class ArbeitnowScraper implements AggregatorScraperInterface
{
    public function __construct(
        private ArbeitnowContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::ARBEITNOW;
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new ArbeitnowConnector())->send(new JobBoardRequest());
            $payload = $response->json();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $jobs = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }
            $externalId = isset($job['slug']) && is_string($job['slug']) ? $job['slug'] : '';
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
                    'context' => ['slug' => $externalId],
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
