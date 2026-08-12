<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\FourDayWeek;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\FourDayWeek\Requests\JobsRssRequest;
use SimpleXMLElement;
use Throwable;

/**
 * 4dayweek.io RSS scraper.
 *
 * Per SOURCES.md §3.6:
 *   - Single RSS endpoint `/jobs.rss` returns the whole curated feed.
 *   - RSS 2.0 standard. Items carry title, link, description, pubDate, guid,
 *     and optionally category.
 *   - Title format is loose ("Company - Role" or "Role at Company"); the
 *     ParserPipeline's CompanyNameExtractor handles the heavy lifting from
 *     the body text — we pass the raw item through structured.
 *
 * The persister is idempotent on (source_id, external_id) so reruns across
 * days are safe.
 */
final readonly class FourDayWeekScraper implements AggregatorScraperInterface
{
    public function __construct(
        private FourDayWeekContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::FOURDAYWEEK;
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new FourDayWeekConnector())->send(new JobsRssRequest());
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
                    'message' => "4dayweek.io RSS feed returned HTTP {$response->status()}.",
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
            // Schema drift (feed structure changed) is indistinguishable from
            // "no jobs today" if we return []; treat it as an error like
            // malformed XML so the run surfaces as partial/error, not a quiet
            // completed run with fetched: 0.
            return null;
        }

        $items = [];
        foreach ($channel->item as $node) {
            $items[] = [
                'guid' => $this->stringChild($node, 'guid'),
                'title' => $this->stringChild($node, 'title'),
                'link' => $this->stringChild($node, 'link'),
                'description' => $this->stringChild($node, 'description'),
                'pubDate' => $this->stringChild($node, 'pubDate'),
                'category' => $this->stringChild($node, 'category'),
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
