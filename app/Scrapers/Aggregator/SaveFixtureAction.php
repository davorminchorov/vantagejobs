<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Writes a Discover listing out as a parser test fixture, per PRD §6.1.5.
 *
 * Path layout:
 *   tests/Fixtures/parsers/{source}/{slug}.json
 *
 * `{source}` is the AggregatorSourceType backing value (hackernews,
 * remotive, ...). `{slug}` is `{source}-{external_id}` when the external
 * id is short + alphanumeric, otherwise `{source}-{first-12-of-md5}`.
 *
 * Body shape:
 *   {
 *     "source": "remotive",
 *     "external_id": "12345",
 *     "raw":        { ...the original listing.raw payload... },
 *     "structured": { ...the SourceContext.structured channel... },
 *     "expected":   { company_name, role_title, seniority, ... }
 *   }
 *
 * `expected` reflects the *current* parsed values post-corrections, not
 * the original parser output, so users can curate a fixture as the
 * ground-truth answer after they've fixed the parse on a triage card.
 *
 * Idempotent: re-saving the same listing overwrites the file in place.
 */
final readonly class SaveFixtureAction
{
    public function __construct(
        private SourceContextFactory $contextFactory,
        private string $baseDir,
    ) {}

    /**
     * @return array{path: string, slug: string}
     */
    public function save(AggregatorListing $listing): array
    {
        $listing->loadMissing('source');
        $source = $listing->source;
        if ($source === null) {
            throw new \RuntimeException("Listing #{$listing->id} has no source.");
        }

        $sourceSlug = $source->type->value;
        $slug = $this->buildSlug($sourceSlug, (string) $listing->external_id);
        $dir = $this->baseDir.'/'.$sourceSlug;
        $path = $dir.'/'.$slug.'.json';

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $context = $this->contextFactory->buildFor($listing);

        $payload = [
            'source' => $sourceSlug,
            'external_id' => (string) $listing->external_id,
            'raw' => $listing->raw,
            'structured' => $context->structured,
            'expected' => $this->expectedFromParsed($listing),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return ['path' => $path, 'slug' => $slug];
    }

    private function buildSlug(string $source, string $externalId): string
    {
        if ($externalId !== '' && strlen($externalId) <= 24 && preg_match('/^[A-Za-z0-9_-]+$/', $externalId) === 1) {
            return $source.'-'.$externalId;
        }

        $hash = substr(md5($externalId), 0, 12);

        return $source.'-'.$hash;
    }

    /**
     * The non-null parsed values, denormalized to a flat fixture-friendly
     * shape. Mirrors the conventions used by HackerNewsParserFixturesTest's
     * expected block.
     *
     * @return array<string, mixed>
     */
    private function expectedFromParsed(AggregatorListing $listing): array
    {
        $out = [];

        if ($listing->parsed_company_name !== null) {
            $out['company_name'] = $listing->parsed_company_name;
        }
        if ($listing->parsed_role_title !== null) {
            $out['role_title'] = $listing->parsed_role_title;
        }
        if ($listing->parsed_seniority !== null) {
            $out['seniority'] = $listing->parsed_seniority;
        }
        if ($listing->parsed_remote_type !== null) {
            $out['remote.type'] = $listing->parsed_remote_type;
        }
        if ($listing->location_bucket !== null) {
            $out['region'] = $listing->location_bucket;
        }
        if ($listing->parsed_comp_min_usd !== null) {
            $out['compensation.min_usd'] = $listing->parsed_comp_min_usd;
        }
        if ($listing->parsed_comp_max_usd !== null) {
            $out['compensation.max_usd'] = $listing->parsed_comp_max_usd;
        }
        if ($listing->stack_tags !== null && $listing->stack_tags !== []) {
            $out['stack_tags_include'] = array_values($listing->stack_tags);
        }

        return $out;
    }
}
