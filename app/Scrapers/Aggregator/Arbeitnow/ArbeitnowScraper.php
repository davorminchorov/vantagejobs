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
 *   - GET /api/job-board-api?page=N, walked from page 1 until `links.next`
 *     comes back null (no more pages) or `max_pages` is hit, whichever
 *     comes first. `max_pages` is a safety cap, not the expected depth —
 *     daily cadence plus this walk is meant to see everything upstream has.
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
        $maxPages = max(1, (int) config('scrapers.aggregators.arbeitnow.max_pages', 5));

        $connector = new ArbeitnowConnector();

        $fetched = 0;
        /** @var list<int> $createdIds */
        $createdIds = [];
        $errors = [];
        /** @var array<string, true> $seenSlugs */
        $seenSlugs = [];
        // Set only when the loop hits max_pages while links.next still points
        // somewhere — i.e. upstream genuinely has more pages than the cap
        // allowed us to fetch.
        $truncated = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $response = $connector->send(new JobBoardRequest($page));
                $payload = $response->json();
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['page' => $page],
                ];

                break;
            }

            $jobs = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            if ($jobs === []) {
                break;
            }

            foreach ($jobs as $job) {
                if (! is_array($job)) {
                    continue;
                }
                $externalId = isset($job['slug']) && is_string($job['slug']) ? $job['slug'] : '';
                if ($externalId === '' || isset($seenSlugs[$externalId])) {
                    continue;
                }
                $seenSlugs[$externalId] = true;

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
                        'context' => ['slug' => $externalId, 'page' => $page],
                    ];
                }
            }

            $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
            $hasNext = isset($links['next']) && is_string($links['next']) && $links['next'] !== '';

            if (! $hasNext) {
                break;
            }

            if ($page === $maxPages) {
                $truncated = true;
            }
        }

        if ($truncated) {
            $errors[] = [
                'message' => "Arbeitnow pagination truncated at max_pages ({$maxPages}); more pages remained upstream.",
                'context' => ['max_pages' => $maxPages, 'fetched' => $fetched],
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
