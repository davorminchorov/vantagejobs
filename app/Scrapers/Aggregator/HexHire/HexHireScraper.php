<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HexHire;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\HexHire\Requests\JobsRssRequest;
use SimpleXMLElement;
use Throwable;

/**
 * HexHire RSS scraper.
 *
 *   - Single RSS endpoint `/rss/jobs.xml` returns the whole active feed
 *     (Elixir/Phoenix-focused job postings).
 *   - Multiple `<category>` elements per item encode employment type,
 *     location/remote strings, and tech tags. Collected into a `categories`
 *     list for downstream extractors.
 *
 * The persister is idempotent on (source_id, external_id) so reruns across
 * days are safe.
 */
final readonly class HexHireScraper implements AggregatorScraperInterface
{
    public function __construct(
        private HexHireContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::HEXHIRE;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.hexhire.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new HexHireConnector())->send(new JobsRssRequest());
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
                    'message' => "HexHire RSS feed returned HTTP {$response->status()}.",
                    'context' => ['http_status' => $response->status()],
                ]],
            );
        }

        $body = (string) $response->body();

        $items = $this->parseItems($body);
        if ($items === null) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => 'malformed RSS XML']],
            );
        }

        $fetched = 0;
        /** @var list<int> $createdIds */
        $createdIds = [];
        $errors = [];

        foreach ($items as $item) {
            $guid = isset($item['guid']) && is_string($item['guid']) ? $item['guid'] : '';
            $link = isset($item['link']) && is_string($item['link']) ? $item['link'] : '';
            $externalId = $guid !== '' ? $guid : $link;
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
                    'context' => ['guid' => $externalId],
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

    /**
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
            // Schema drift (feed structure changed) is indistinguishable from
            // "no jobs today" if we return []; treat it as an error like
            // malformed XML so the run surfaces as partial/error, not a quiet
            // completed run with fetched: 0.
            return null;
        }

        $items = [];
        foreach ($channel->item as $node) {
            $categories = [];
            foreach ($node->category as $cat) {
                $value = trim((string) $cat);
                if ($value !== '') {
                    $categories[] = $value;
                }
            }

            $items[] = [
                'title' => trim((string) ($node->title ?? '')),
                'link' => trim((string) ($node->link ?? '')),
                'description' => trim((string) ($node->description ?? '')),
                'guid' => trim((string) ($node->guid ?? '')),
                'pubDate' => trim((string) ($node->pubDate ?? '')),
                'categories' => $categories,
            ];
        }

        return $items;
    }
}
