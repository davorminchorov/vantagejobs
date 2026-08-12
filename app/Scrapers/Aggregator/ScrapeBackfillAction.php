<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ScrapeRunArtifact;
use App\Scrapers\Aggregator\HackerNews\HackerNewsAlgoliaConnector;
use App\Scrapers\Aggregator\HackerNews\HackerNewsContextBuilder;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindHistoricalThreadsRequest;
use App\Scrapers\Aggregator\Himalayas\HimalayasContextBuilder;
use App\Scrapers\Aggregator\Parser\SourceContext;
use App\Scrapers\Aggregator\RemoteOk\RemoteOkContextBuilder;
use App\Scrapers\Aggregator\Remotive\RemotiveContextBuilder;
use App\Scrapers\Aggregator\WeWorkRemotely\WeWorkRemotelyContextBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Backfill listings for one aggregator source.
 *
 * Two strategies, picked per source kind:
 *
 *   - HackerNews: re-pulls the last N monthly "Who is Hiring" threads from
 *     the Algolia API (one fetch per thread) and runs each through the
 *     existing scrape path. Algolia is the only source with proper history.
 *
 *   - Everything else (Remotive / RemoteOK / WWR / Himalayas): replays
 *     previously-captured raw HTTP responses from scrape_run_artifacts. The
 *     Saloon RecordResponseArtifact middleware gzips and stores them; we
 *     gunzip, decode, and feed them through each source's context builder
 *     + the shared persister. The persister is idempotent on (source_id,
 *     external_id) so replayed responses update fetched_at and don't dupe.
 *     Days older than the retention window are reported as `out_of_retention`.
 *
 * The whole class is reachable from one entry point so the artisan command
 * stays thin.
 */
final readonly class ScrapeBackfillAction
{
    public function __construct(
        private AggregatorListingPersister $persister,
        private HackerNewsContextBuilder $hackerNews,
        private RemotiveContextBuilder $remotive,
        private HimalayasContextBuilder $himalayas,
        private WeWorkRemotelyContextBuilder $weWorkRemotely,
        private RemoteOkContextBuilder $remoteOk,
        private FilesystemFactory $filesystem,
        private LoggerInterface $logger,
    ) {}

    /**
     * Source types with a replay strategy implemented below. Kept in sync
     * with the match() arms so ScrapeBackfillCommand can validate --source
     * against this exact list instead of the full AggregatorSourceType
     * enum (which has grown well past what this class supports).
     *
     * @var list<AggregatorSourceType>
     */
    public const SUPPORTED_TYPES = [
        AggregatorSourceType::HACKERNEWS,
        AggregatorSourceType::REMOTIVE,
        AggregatorSourceType::HIMALAYAS,
        AggregatorSourceType::WEWORKREMOTELY,
        AggregatorSourceType::REMOTEOK,
    ];

    public function backfill(AggregatorSource $source, int $days): BackfillResult
    {
        return match ($source->type) {
            AggregatorSourceType::HACKERNEWS => $this->backfillHackerNews($source, $days),
            AggregatorSourceType::REMOTIVE => $this->backfillFromArtifacts($source, $days, fn (string $body) => $this->replayRemotiveBody($source, $body)),
            AggregatorSourceType::HIMALAYAS => $this->backfillFromArtifacts($source, $days, fn (string $body) => $this->replayHimalayasBody($source, $body)),
            AggregatorSourceType::WEWORKREMOTELY => $this->backfillFromArtifacts($source, $days, fn (string $body) => $this->replayWeWorkRemotelyBody($source, $body)),
            AggregatorSourceType::REMOTEOK => $this->backfillFromArtifacts($source, $days, fn (string $body) => $this->replayRemoteOkBody($source, $body)),
            default => throw new RuntimeException(sprintf(
                'Backfill not implemented for source [%s]. Supported: %s.',
                $source->type->value,
                implode(', ', array_map(static fn (AggregatorSourceType $t) => $t->value, self::SUPPORTED_TYPES)),
            )),
        };
    }

    private function backfillHackerNews(AggregatorSource $source, int $months): BackfillResult
    {
        $connector = new HackerNewsAlgoliaConnector;
        $errors = [];

        try {
            $searchResponse = $connector->send(new FindHistoricalThreadsRequest($months));
            $hits = $this->extractHits($searchResponse->json());
        } catch (Throwable $e) {
            return new BackfillResult(
                source: $source->type->value,
                daysRequested: $months,
                daysProcessed: 0,
                threadsPulled: 0,
                newListings: 0,
                updatedListings: 0,
                outOfRetention: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $threadsPulled = 0;
        $newCount = 0;
        $updatedCount = 0;

        foreach ($hits as $hit) {
            $storyId = $hit['objectID'] ?? $hit['story_id'] ?? null;
            if ($storyId === null) {
                continue;
            }
            $storyId = (string) $storyId;

            try {
                $threadResponse = $connector->send(new FetchThreadRequest($storyId));
                $tree = $threadResponse->json();
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['story_id' => $storyId],
                ];

                continue;
            }

            $threadsPulled++;

            $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];

            foreach ($children as $comment) {
                if (! is_array($comment) || ! $this->shouldKeepHackerNewsComment($comment)) {
                    continue;
                }

                $externalId = isset($comment['id']) ? (string) $comment['id'] : '';
                if ($externalId === '') {
                    continue;
                }

                $existed = $this->listingExists($source->id, $externalId);

                try {
                    $context = $this->hackerNews->build($this->normalizeHackerNewsComment($comment));
                    $newId = $this->persister->upsert($source, $context, $comment, $externalId);

                    if ($newId !== null) {
                        $newCount++;
                    } elseif ($existed) {
                        $updatedCount++;
                    }
                } catch (Throwable $e) {
                    $errors[] = [
                        'message' => $e->getMessage(),
                        'context' => ['comment_id' => $comment['id'] ?? null, 'story_id' => $storyId],
                    ];
                }
            }
        }

        return new BackfillResult(
            source: $source->type->value,
            daysRequested: $months,
            daysProcessed: $threadsPulled,
            threadsPulled: $threadsPulled,
            newListings: $newCount,
            updatedListings: $updatedCount,
            outOfRetention: 0,
            errors: $errors,
        );
    }

    /**
     * Replay every artifact captured for $source within the last $days days
     * (capped at the configured retention window). Anything older than the
     * window has already been swept, so it's reported as out_of_retention
     * and skipped.
     *
     * @param  callable(string): array{new: int, updated: int, errors: list<array{message: string, context?: array<string, mixed>}>}  $replay
     */
    private function backfillFromArtifacts(AggregatorSource $source, int $days, callable $replay): BackfillResult
    {
        $retentionDays = (int) config('scrapers.artifact_retention_days', 7);
        $effectiveDays = min($days, $retentionDays);
        $outOfRetention = max(0, $days - $retentionDays);

        $disk = $this->filesystem->disk('local');
        $cutoff = CarbonImmutable::now()->subDays($effectiveDays);

        $artifacts = ScrapeRunArtifact::query()
            ->where('source_kind', 'aggregator')
            ->where('source_id', $source->id)
            ->where('fetched_at', '>=', $cutoff)
            ->orderBy('fetched_at')
            ->get();

        $errors = [];
        $newCount = 0;
        $updatedCount = 0;
        $processedDays = [];

        foreach ($artifacts as $artifact) {
            try {
                if (! $disk->exists($artifact->path)) {
                    $errors[] = [
                        'message' => 'artifact file missing',
                        'context' => ['artifact_id' => (int) $artifact->id, 'path' => $artifact->path],
                    ];

                    continue;
                }

                $gzipped = $disk->get($artifact->path);
                if (! is_string($gzipped) || $gzipped === '') {
                    $errors[] = [
                        'message' => 'artifact body empty',
                        'context' => ['artifact_id' => (int) $artifact->id],
                    ];

                    continue;
                }

                $body = gzdecode($gzipped);
                if ($body === false) {
                    $errors[] = [
                        'message' => 'gzdecode failed',
                        'context' => ['artifact_id' => (int) $artifact->id],
                    ];

                    continue;
                }

                $outcome = $replay($body);
                $newCount += $outcome['new'];
                $updatedCount += $outcome['updated'];
                if ($outcome['errors'] !== []) {
                    $errors = array_merge($errors, $outcome['errors']);
                }
            } catch (Throwable $e) {
                $this->logger->warning('scrape:backfill replay failed', [
                    'artifact_id' => (int) $artifact->id,
                    'message' => $e->getMessage(),
                ]);
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['artifact_id' => (int) $artifact->id],
                ];

                continue;
            }

            $processedDays[$artifact->fetched_at->toDateString()] = true;
        }

        return new BackfillResult(
            source: $source->type->value,
            daysRequested: $days,
            daysProcessed: count($processedDays),
            threadsPulled: 0,
            newListings: $newCount,
            updatedListings: $updatedCount,
            outOfRetention: $outOfRetention,
            errors: $errors,
        );
    }

    /**
     * @return array{new: int, updated: int, errors: list<array{message: string, context?: array<string, mixed>}>}
     */
    private function replayRemotiveBody(AggregatorSource $source, string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return ['new' => 0, 'updated' => 0, 'errors' => [['message' => 'remotive body is not JSON']]];
        }
        $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

        return $this->replayStructuredJobs(
            $source,
            $jobs,
            externalIdKey: 'id',
            buildContext: fn (array $job): SourceContext => $this->remotive->build($job),
        );
    }

    /**
     * @return array{new: int, updated: int, errors: list<array{message: string, context?: array<string, mixed>}>}
     */
    private function replayHimalayasBody(AggregatorSource $source, string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return ['new' => 0, 'updated' => 0, 'errors' => [['message' => 'himalayas body is not JSON']]];
        }
        $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

        return $this->replayStructuredJobs(
            $source,
            $jobs,
            externalIdKey: 'guid',
            buildContext: fn (array $job): SourceContext => $this->himalayas->build($job),
        );
    }

    /**
     * @return array{new: int, updated: int, errors: list<array{message: string, context?: array<string, mixed>}>}
     */
    private function replayRemoteOkBody(AggregatorSource $source, string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return ['new' => 0, 'updated' => 0, 'errors' => [['message' => 'remoteok body is not JSON']]];
        }
        $items = array_values($payload);
        // First element is the legal-notice envelope (per SOURCES.md §2.5).
        array_shift($items);

        return $this->replayStructuredJobs(
            $source,
            $items,
            externalIdKey: 'id',
            buildContext: fn (array $job): SourceContext => $this->remoteOk->build($job),
        );
    }

    /**
     * @return array{new: int, updated: int, errors: list<array{message: string, context?: array<string, mixed>}>}
     */
    private function replayWeWorkRemotelyBody(AggregatorSource $source, string $body): array
    {
        $items = $this->parseWeWorkRemotelyRss($body);
        if ($items === null) {
            return ['new' => 0, 'updated' => 0, 'errors' => [['message' => 'weworkremotely body is not valid RSS']]];
        }

        return $this->replayStructuredJobs(
            $source,
            $items,
            externalIdKey: 'guid',
            buildContext: fn (array $item): SourceContext => $this->weWorkRemotely->build($item),
        );
    }

    /**
     * Common iterator: walks a list of structured job records, builds a
     * context per item, and pushes through the persister. Skips items
     * without an external id.
     *
     * @param  list<mixed>  $items
     * @param  callable(array<string, mixed>): SourceContext  $buildContext
     * @return array{new: int, updated: int, errors: list<array{message: string, context?: array<string, mixed>}>}
     */
    private function replayStructuredJobs(AggregatorSource $source, array $items, string $externalIdKey, callable $buildContext): array
    {
        $newCount = 0;
        $updatedCount = 0;
        $errors = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $externalId = isset($item[$externalIdKey]) ? (string) $item[$externalIdKey] : '';
            if ($externalId === '') {
                continue;
            }

            $existed = $this->listingExists($source->id, $externalId);

            try {
                $context = $buildContext($item);
                $newId = $this->persister->upsert($source, $context, $item, $externalId);

                if ($newId !== null) {
                    $newCount++;
                } elseif ($existed) {
                    $updatedCount++;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => [$externalIdKey => $externalId],
                ];
            }
        }

        return ['new' => $newCount, 'updated' => $updatedCount, 'errors' => $errors];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function parseWeWorkRemotelyRss(string $body): ?array
    {
        if (trim($body) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA);
        } catch (Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return null;
        }

        $channel = $xml->channel ?? null;
        if ($channel === null) {
            return [];
        }

        $items = [];
        foreach ($channel->item as $node) {
            $items[] = [
                'guid' => trim((string) ($node->guid ?? '')),
                'title' => trim((string) ($node->title ?? '')),
                'link' => trim((string) ($node->link ?? '')),
                'region' => trim((string) ($node->region ?? '')),
                'description' => trim((string) ($node->description ?? '')),
                'pubDate' => trim((string) ($node->pubDate ?? '')),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    private function shouldKeepHackerNewsComment(array $comment): bool
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

    /**
     * @param  array<string, mixed>  $comment
     * @return array{id?: int|string, author?: string, text?: string, created_at?: string}
     */
    private function normalizeHackerNewsComment(array $comment): array
    {
        $out = [];
        if (isset($comment['id']) && (is_string($comment['id']) || is_int($comment['id']))) {
            $out['id'] = $comment['id'];
        }
        if (isset($comment['author']) && is_string($comment['author'])) {
            $out['author'] = $comment['author'];
        }
        if (isset($comment['text']) && is_string($comment['text'])) {
            $out['text'] = $comment['text'];
        }
        if (isset($comment['created_at']) && is_string($comment['created_at'])) {
            $out['created_at'] = $comment['created_at'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractHits(array $payload): array
    {
        $hits = $payload['hits'] ?? null;
        if (! is_array($hits)) {
            return [];
        }

        $out = [];
        foreach ($hits as $hit) {
            if (is_array($hit)) {
                $out[] = $hit;
            }
        }

        return $out;
    }

    private function listingExists(int $sourceId, string $externalId): bool
    {
        return AggregatorListing::query()
            ->where('source_id', $sourceId)
            ->where('external_id', $externalId)
            ->exists();
    }
}
