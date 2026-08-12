<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WorkingNomads;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\WorkingNomads\Requests\ExposedJobsRequest;
use Throwable;

/**
 * Working Nomads aggregator scraper.
 *
 * Per SOURCES.md §3.7:
 *   - Single GET against /api/exposed_jobs/ returns the full job list as a
 *     flat JSON array (no envelope, no pagination).
 *   - No stable numeric id ships with each record, so we derive
 *     external_id = sha1(url). Jobs without a `url` are skipped — there's
 *     no key to dedupe on.
 *   - Polite-poll source, concurrency stays at 1.
 */
final readonly class WorkingNomadsScraper implements AggregatorScraperInterface
{
    public function __construct(
        private WorkingNomadsContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::WORKINGNOMADS;
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new WorkingNomadsConnector())->send(new ExposedJobsRequest());
            $payload = $response->json();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $jobs = is_array($payload) ? $payload : [];

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            $url = isset($job['url']) && is_string($job['url']) ? $job['url'] : '';
            if ($url === '') {
                continue;
            }

            $externalId = sha1($url);
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
                    'context' => ['job_url' => $url],
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
