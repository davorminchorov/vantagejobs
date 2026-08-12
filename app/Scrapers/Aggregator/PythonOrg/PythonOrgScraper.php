<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\PythonOrg;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\PythonOrg\Requests\JobsRssRequest;
use SimpleXMLElement;
use Throwable;

/**
 * Python.org official jobs RSS scraper.
 *
 *   - Single RSS endpoint /jobs/feed/rss/ returns the latest 20 jobs.
 *   - Plain RSS 2.0 (only `xmlns:atom` declared). Items have title, link,
 *     description, guid. No pubDate.
 *   - external_id = `<guid>` (canonical permalink).
 */
final readonly class PythonOrgScraper implements AggregatorScraperInterface
{
    public function __construct(
        private PythonOrgContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::PYTHON_ORG;
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new PythonOrgConnector())->send(new JobsRssRequest());
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
                'title' => trim((string) ($node->title ?? '')),
                'link' => trim((string) ($node->link ?? '')),
                'description' => trim((string) ($node->description ?? '')),
                'guid' => trim((string) ($node->guid ?? '')),
            ];
        }

        return $items;
    }
}
