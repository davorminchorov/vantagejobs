<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindLatestThreadRequest;
use App\Scrapers\Aggregator\Parser\ParsedListing;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * HackerNews "Who is Hiring" scraper.
 *
 * Two-step fetch:
 *   1. Find the latest thread via Algolia search.
 *   2. Fetch the thread tree, iterate top-level children, filter, parse, and
 *      persist each as an AggregatorListing.
 *
 * Idempotent on (tenant_id, source_id, external_id) where external_id is the
 * HackerNews comment ID. Reruns against the same thread update rows in
 * place — newCount stays 0 on the second pass.
 *
 * Filter rules per SOURCES.md §2.1:
 *   - Skip deleted/dead comments
 *   - Skip if text-stripped body < 100 chars
 *   - Skip if author is "whoishiring" (the OP)
 */
final readonly class HackerNewsScraper implements AggregatorScraperInterface
{
    public function __construct(
        private ParserPipeline $pipeline,
        private HackerNewsContextBuilder $contextBuilder,
        private CompanyKeyNormalizer $normalizer,
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
        $errors = [];

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

        foreach ($children as $comment) {
            if (! is_array($comment)) {
                continue;
            }
            if (! $this->shouldKeep($comment)) {
                continue;
            }

            $fetched++;

            try {
                if ($this->upsertListing($source, $comment)) {
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
        // Algolia represents deleted/dead comments with null text.
        $text = $comment['text'] ?? null;
        if (! is_string($text) || $text === '') {
            return false;
        }

        // Min 100 chars on stripped body so we skip empty re-posts.
        $stripped = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        if (mb_strlen($stripped) < 100) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $comment
     * @return bool true if a new row was inserted, false if an existing row was updated
     */
    private function upsertListing(AggregatorSource $source, array $comment): bool
    {
        $externalId = (string) ($comment['id'] ?? '');
        if ($externalId === '') {
            return false;
        }

        $context = $this->contextBuilder->build($comment);
        $parsed = $this->pipeline->parse($context);

        $existing = AggregatorListing::query()
            ->where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        $attrs = $this->buildAttributes($source, $context, $parsed, $comment, $externalId);

        if ($existing === null) {
            AggregatorListing::query()->create($attrs);

            return true;
        }

        // Preserve user-set status (kept / dismissed / snoozed) on re-fetch.
        unset($attrs['status']);
        $existing->fill($attrs)->save();

        return false;
    }

    /**
     * @param  array<string, mixed>  $rawComment
     * @return array<string, mixed>
     */
    private function buildAttributes(
        AggregatorSource $source,
        SourceContext $context,
        ParsedListing $parsed,
        array $rawComment,
        string $externalId,
    ): array {
        $compensation = $parsed->value('compensation');
        $remote = $parsed->value('remote');
        $stackTags = $parsed->value('stack_tags');

        $minUsd = is_array($compensation) && isset($compensation['min_usd']) ? $compensation['min_usd'] : null;
        $maxUsd = is_array($compensation) && isset($compensation['max_usd']) ? $compensation['max_usd'] : null;
        $hasComp = is_array($compensation) && ($minUsd !== null || $maxUsd !== null);

        return [
            'source_id' => $source->id,
            'external_id' => $externalId,
            'url' => $context->url,
            'raw' => $rawComment,
            'parsed' => $parsed->toArray(),
            'parsed_company_name' => $this->asString($parsed->value('company_name')),
            'parsed_role_title' => $this->asString($parsed->value('role_title')),
            'parsed_seniority' => $this->asString($parsed->value('seniority')),
            'parsed_remote_type' => is_array($remote) ? ($remote['type'] ?? null) : null,
            'parsed_comp_min_usd' => $minUsd,
            'parsed_comp_max_usd' => $maxUsd,
            'has_comp' => $hasComp,
            'stack_tags' => is_array($stackTags) ? $stackTags : null,
            'normalized_company_key' => $this->normalizer->normalize($this->asString($parsed->value('company_name'))),
            'normalized_role_key' => $this->normalizer->normalize($this->asString($parsed->value('role_title'))),
            'location_bucket' => $this->asString($parsed->value('region')),
            'status' => AggregatorListingStatus::NEW->value,
            'posted_at' => $context->postedAt,
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
