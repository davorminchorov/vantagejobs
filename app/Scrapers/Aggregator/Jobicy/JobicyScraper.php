<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\Jobicy\Requests\RemoteJobsRequest;
use Throwable;

/**
 * Jobicy remote-jobs JSON scraper.
 *
 *   - Single GET against /api/v2/remote-jobs returns up to `count` jobs.
 *   - external_id = the integer `id` field, stringified.
 *   - Politeness: Jobicy documents a 1-req/hr limit; daily cadence is well
 *     inside it. Concurrency 1 in config.
 */
final readonly class JobicyScraper implements AggregatorScraperInterface
{
    public function __construct(
        private JobicyContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::JOBICY;
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new JobicyConnector())->send(new RemoteJobsRequest());
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
