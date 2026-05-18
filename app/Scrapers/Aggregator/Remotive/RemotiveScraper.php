<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Remotive;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\Remotive\Requests\RemoteJobsRequest;
use Throwable;

/**
 * Remotive software-dev category scraper.
 *
 * Per SOURCES.md §2.2:
 *   - One fetch returns the entire category listing in `jobs[]`.
 *   - The top-level "0-legal-notice" key is informational — we don't iterate
 *     it; we read `jobs[]` directly.
 *   - Rate limit is strict (≤ 2 req/min), so concurrency stays at 1 and the
 *     scheduler polls once daily.
 *
 * The structured JSON keys (`company_name`, `title`, `tags`, `salary`,
 * `candidate_required_location`, `job_type`, `url`, `description`) all map
 * cleanly into what the extractors already read, so the context builder is
 * thin — strip HTML for bodyText, parse `publication_date` as UTC, done.
 */
final readonly class RemotiveScraper implements AggregatorScraperInterface
{
    public function __construct(
        private RemotiveContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::REMOTIVE;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.remotive.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new RemotiveConnector())->send(new RemoteJobsRequest());
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
            $externalId = isset($job['id']) ? (string) $job['id'] : '';
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
                    'context' => ['job_id' => $externalId],
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
