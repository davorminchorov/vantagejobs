<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\ElixirJobs;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\ElixirJobs\Requests\JobsRssRequest;
use SimpleXMLElement;
use Throwable;

/**
 * Elixir Jobs RSS scraper.
 *
 *   - Single RSS endpoint `/rss` returns the whole active feed (Elixir jobs).
 *   - Plain RSS 2.0 with no custom namespaces — title, description, pubDate,
 *     link, and guid are sufficient for extraction.
 *   - pubDate uses a non-standard format without a day-of-week prefix
 *     ("07 May 2026 09:51:29 +0000") but Carbon::parse handles it correctly.
 *
 * The persister is idempotent on (source_id, external_id) so reruns across
 * days are safe.
 */
final readonly class ElixirJobsScraper implements AggregatorScraperInterface
{
    public function __construct(
        private ElixirJobsContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::ELIXIR_JOBS;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.elixir_jobs.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new ElixirJobsConnector())->send(new JobsRssRequest());
            $body = (string) $response->body();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

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
            return [];
        }

        $items = [];
        foreach ($channel->item as $node) {
            $items[] = [
                'guid' => $this->stringChild($node, 'guid'),
                'title' => $this->stringChild($node, 'title'),
                'link' => $this->stringChild($node, 'link'),
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
