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
 *   - The source is XML, not JSON. We parse the umbrella feed plus three
 *     category feeds (back-end, full-stack, programming) using SimpleXML
 *     and walk channel.item.
 *   - ToS forbids non-RSS scraping; the authenticated partner API is out of
 *     scope.
 *   - No salary or structured tags in the feed — comp falls through as
 *     MISSING and stack tags are inferred from body text.
 *
 * Cross-feed dedup happens in-process: the same `<guid>` often appears under
 * multiple feeds, so we keep a per-run seen-set and skip duplicates before
 * they reach the persister. The persister itself is idempotent on
 * (source_id, external_id), so reruns across days are also safe.
 */
final readonly class WeWorkRemotelyScraper implements AggregatorScraperInterface
{
    /**
     * The umbrella feed (slug null, `/remote-jobs.rss`) covers every
     * category and is fetched first, so it's the one that decides coverage;
     * the category feeds after it exist as a backstop in case the umbrella
     * feed is briefly stale or unavailable, and in the common case they
     * contribute nothing but cross-feed dedup skips.
     *
     * @var list<string|null>
     */
    private const FEED_SLUGS = [
        null,
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

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        $connector = new WeWorkRemotelyConnector();

        $fetched = 0;
        /** @var list<int> $createdIds */
        $createdIds = [];
        $errors = [];
        /** @var array<string, true> $seenGuids */
        $seenGuids = [];

        foreach (self::FEED_SLUGS as $slug) {
            $label = $slug ?? 'umbrella';

            try {
                $response = $connector->send(new CategoryFeedRequest($slug));
                $body = (string) $response->body();
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['category' => $label],
                ];

                continue;
            }

            $items = $this->parseItems($body);
            if ($items === null) {
                $errors[] = [
                    'message' => 'malformed RSS XML',
                    'context' => ['category' => $label],
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
                    $id = $this->persister->upsert($source, $context, $item, $externalId);
                    if ($id !== null) {
                        $createdIds[] = $id;
                    }
                } catch (Throwable $e) {
                    $errors[] = [
                        'message' => $e->getMessage(),
                        'context' => ['guid' => $externalId, 'category' => $label],
                    ];
                }
            }
        }

        $this->persister->capRun($createdIds);

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: count($createdIds),
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
