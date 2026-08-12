<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\RemoteOk;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\RemoteOk\Requests\JobsRequest;
use Throwable;

/**
 * RemoteOK aggregator scraper.
 *
 * Per SOURCES.md §2.5:
 *   - One fetch against /api returns the entire listing as a JSON array.
 *   - **The first array element is metadata/legal** ({"legal": ..., "warning": ...})
 *     and must be skipped.
 *   - Rate limits are undocumented; concurrency stays at 1 and the scheduler
 *     polls once daily.
 *
 * The structured JSON keys (`id`, `position`, `company`, `tags`, `salary_min`,
 * `salary_max`, `location`, `apply_url`, `url`, `description`, `date`) all
 * map cleanly into what the extractors already read, so the context builder
 * is thin — strip HTML for bodyText, parse `date` as UTC, done.
 */
final readonly class RemoteOkScraper implements AggregatorScraperInterface
{
    public function __construct(
        private RemoteOkContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::REMOTEOK;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.remoteok.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new RemoteOkConnector())->send(new JobsRequest());
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        if ($response->status() >= 400) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [[
                    'message' => "RemoteOK API returned HTTP {$response->status()}.",
                    'context' => ['http_status' => $response->status()],
                ]],
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return new AggregatorRunResult(fetched: 0, newCount: 0);
        }

        // Re-index so index 0 (metadata/legal element) is reliably positioned.
        $items = array_values($payload);

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            // Skip the metadata/legal envelope at index 0 per SOURCES.md §2.5
            // - it carries no `id` key. If a future API response ever puts a
            // real job first (no envelope), process it instead of dropping it.
            if ($index === 0 && ! array_key_exists('id', $item)) {
                continue;
            }
            $externalId = isset($item['id']) ? (string) $item['id'] : '';
            if ($externalId === '') {
                continue;
            }

            $fetched++;

            try {
                $context = $this->contextBuilder->build($item);
                $id = $this->persister->upsert($source, $context, $item, $externalId);
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
