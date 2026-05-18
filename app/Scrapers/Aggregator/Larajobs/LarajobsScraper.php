<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Larajobs;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\Larajobs\Requests\JobsRssRequest;
use SimpleXMLElement;
use Throwable;

/**
 * LaraJobs RSS scraper.
 *
 *   - Single RSS endpoint `/feed` returns the whole active feed (Laravel jobs).
 *   - Custom `job:*` and `dc:*` namespaced elements carry the structured fields
 *     (company, location, salary, tags, employment type). We flatten them into
 *     the same associative shape downstream extractors already consume.
 *
 * The persister is idempotent on (source_id, external_id) so reruns across
 * days are safe.
 */
final readonly class LarajobsScraper implements AggregatorScraperInterface
{
    private const NS_JOB = 'https://larajobs.com';

    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    public function __construct(
        private LarajobsContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::LARAJOBS;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.larajobs.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new LarajobsConnector())->send(new JobsRssRequest());
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
            $jobNs = $node->children(self::NS_JOB);
            $dcNs = $node->children(self::NS_DC);
            $contentNs = $node->children(self::NS_CONTENT);

            $contentEncoded = $contentNs->encoded ?? null;
            $description = $this->stringChild($node, 'description');
            if ($description === '' && $contentEncoded !== null) {
                $description = trim((string) $contentEncoded);
            }

            $companyName = trim((string) ($jobNs->company ?? ''));
            if ($companyName === '') {
                $companyName = trim((string) ($dcNs->creator ?? ''));
            }

            $tagsRaw = trim((string) ($jobNs->tags ?? ''));
            $tags = $tagsRaw === '' ? [] : array_values(array_filter(
                array_map('trim', explode(',', $tagsRaw)),
                static fn (string $t): bool => $t !== '',
            ));

            $items[] = [
                'guid' => $this->stringChild($node, 'guid'),
                'title' => $this->stringChild($node, 'title'),
                'link' => $this->stringChild($node, 'link'),
                'description' => $description,
                'pubDate' => $this->stringChild($node, 'pubDate'),
                'category' => $this->stringChild($node, 'category'),
                'companyName' => $companyName,
                'location' => trim((string) ($jobNs->location ?? '')),
                'employment_type' => trim((string) ($jobNs->job_type ?? '')),
                'salary' => trim((string) ($jobNs->salary ?? '')),
                'tags' => $tags,
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
