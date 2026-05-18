<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WeWorkRemotely;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\WeWorkRemotely\Requests\CategoryFeedRequest;
use SimpleXMLElement;
use Throwable;

/**
 * We Work Remotely RSS scraper.
 *
 * Per SOURCES.md §2.4:
 *   - The source is XML, not JSON. We parse three category feeds (back-end,
 *     full-stack, programming) using SimpleXML and walk channel.item.
 *   - ToS forbids non-RSS scraping; the authenticated partner API is out of
 *     scope.
 *   - No salary or structured tags in the feed — comp falls through as
 *     MISSING and stack tags are inferred from body text.
 *
 * Cross-feed dedup happens in-process: the same `<guid>` often appears under
 * multiple categories, so we keep a per-run seen-set and skip duplicates
 * before they reach the persister. The persister itself is idempotent on
 * (source_id, external_id), so reruns across days are also safe.
 */
final readonly class WeWorkRemotelyScraper implements AggregatorScraperInterface
{
    /**
     * Per SOURCES.md §2.4 — the three category feeds we ingest for v1.
     *
     * @var list<string>
     */
    private const CATEGORY_SLUGS = [
        'remote-back-end-programming-jobs',
        'remote-full-stack-programming-jobs',
        'remote-programming-jobs',
    ];

    public function __construct(
        private WeWorkRemotelyContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::WEWORKREMOTELY;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.weworkremotely.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        $connector = new WeWorkRemotelyConnector();

        $fetched = 0;
        $newCount = 0;
        $errors = [];
        /** @var array<string, true> $seenGuids */
        $seenGuids = [];

        foreach (self::CATEGORY_SLUGS as $slug) {
            try {
                $response = $connector->send(new CategoryFeedRequest($slug));
                $body = (string) $response->body();
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['category' => $slug],
                ];

                continue;
            }

            $items = $this->parseItems($body);
            if ($items === null) {
                $errors[] = [
                    'message' => 'malformed RSS XML',
                    'context' => ['category' => $slug],
                ];

                continue;
            }

            foreach ($items as $item) {
                $externalId = isset($item['guid']) && is_string($item['guid']) ? $item['guid'] : '';
                if ($externalId === '' || isset($seenGuids[$externalId])) {
                    continue;
                }
                $seenGuids[$externalId] = true;

                $fetched++;

                try {
                    $context = $this->contextBuilder->build($item);
                    if ($this->persister->upsert($source, $context, $item, $externalId)) {
                        $newCount++;
                    }
                } catch (Throwable $e) {
                    $errors[] = [
                        'message' => $e->getMessage(),
                        'context' => ['guid' => $externalId, 'category' => $slug],
                    ];
                }
            }
        }

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: $newCount,
            errors: $errors,
        );
    }

    /**
     * Parse an RSS body into a list of item associative arrays. Returns null
     * on malformed XML so the scraper can record a structured error.
     *
     * @return list<array<string, mixed>>|null
     */
    private function parseItems(string $body): ?array
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
                'guid' => $this->stringChild($node, 'guid'),
                'title' => $this->stringChild($node, 'title'),
                'link' => $this->stringChild($node, 'link'),
                'region' => $this->stringChild($node, 'region'),
                'description' => $this->stringChild($node, 'description'),
                'pubDate' => $this->stringChild($node, 'pubDate'),
            ];
        }

        return $items;
    }

    private function stringChild(SimpleXMLElement $node, string $tag): string
    {
        $child = $node->{$tag} ?? null;
        if ($child === null) {
            return '';
        }

        return trim((string) $child);
    }
}
