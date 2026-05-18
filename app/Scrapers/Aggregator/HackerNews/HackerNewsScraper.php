<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindLatestThreadRequest;
use Throwable;

/**
 * HackerNews "Who is Hiring" scraper.
 *
 * Two-step fetch:
 *   1. Find the latest thread via Algolia search.
 *   2. Fetch the thread tree, iterate top-level children, filter, parse, and
 *      persist each as an AggregatorListing.
 *
 * Filter rules per SOURCES.md §2.1:
 *   - Skip deleted/dead comments
 *   - Skip if text-stripped body < 100 chars
 *   - Skip if author is "whoishiring" (the OP)
 *
 * Parse-and-persist is delegated to AggregatorListingPersister so all five
 * aggregator scrapers share identical denormalization + idempotency rules.
 */
final readonly class HackerNewsScraper implements AggregatorScraperInterface
{
    public function __construct(
        private HackerNewsContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::HACKERNEWS;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.hackernews.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $connector = new HackerNewsAlgoliaConnector();

            $searchResponse = $connector->send(new FindLatestThreadRequest());
            $storyId = $this->extractLatestStoryId($searchResponse->json());

            if ($storyId === null) {
                return new AggregatorRunResult(
                    fetched: 0,
                    newCount: 0,
                    errors: [['message' => 'No HackerNews "Who is Hiring" thread found in search response.']],
                );
            }

            $threadResponse = $connector->send(new FetchThreadRequest($storyId));
            $tree = $threadResponse->json();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];

        $fetched = 0;
        $newCount = 0;
        $errors = [];

        foreach ($children as $comment) {
            if (! is_array($comment) || ! $this->shouldKeep($comment)) {
                continue;
            }

            $fetched++;

            try {
                $context = $this->contextBuilder->build($comment);
                $externalId = (string) ($comment['id'] ?? '');

                if ($this->persister->upsert($source, $context, $comment, $externalId)) {
                    $newCount++;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['comment_id' => $comment['id'] ?? null],
                ];
            }
        }

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: $newCount,
            errors: $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $searchResponse
     */
    private function extractLatestStoryId(array $searchResponse): ?string
    {
        $hits = $searchResponse['hits'] ?? null;
        if (! is_array($hits) || $hits === []) {
            return null;
        }

        $first = $hits[0];
        if (! is_array($first)) {
            return null;
        }

        $objectId = $first['objectID'] ?? $first['story_id'] ?? null;

        return $objectId === null ? null : (string) $objectId;
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    private function shouldKeep(array $comment): bool
    {
        if (($comment['author'] ?? null) === 'whoishiring') {
            return false;
        }

        $text = $comment['text'] ?? null;
        if (! is_string($text) || $text === '') {
            return false;
        }

        $stripped = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($stripped) >= 100;
    }
}
