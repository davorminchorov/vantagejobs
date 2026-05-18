<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Event;
use App\Scrapers\Aggregator\Parser\ParsedListing;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;

/**
 * Runs the ParserPipeline against a SourceContext and persists the result
 * as an AggregatorListing. Idempotent on (source_id, external_id): reruns
 * update rows in place, preserving any user-set status (kept / dismissed /
 * snoozed) the triage UI may have written downstream.
 *
 * Shared by every aggregator scraper. Each scraper only owns its fetch +
 * filter + context-building; this service owns the parse-and-persist tail.
 */
final readonly class AggregatorListingPersister
{
    public function __construct(
        private ParserPipeline $pipeline,
        private CompanyKeyNormalizer $normalizer,
        private MatchCompanyAction $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $rawRecord
     * @return bool true if a new row was inserted, false if an existing row was updated
     */
    public function upsert(
        AggregatorSource $source,
        SourceContext $context,
        array $rawRecord,
        string $externalId,
    ): bool {
        if ($externalId === '') {
            return false;
        }

        $parsed = $this->pipeline->parse($context);

        $existing = AggregatorListing::query()
            ->where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        $attrs = $this->buildAttributes($source, $context, $parsed, $rawRecord, $externalId);

        if ($existing === null) {
            /** @var AggregatorListing $listing */
            $listing = AggregatorListing::query()->create($attrs);
            $this->attemptAutoLink($listing, $parsed);

            return true;
        }

        // Preserve user-set status on re-fetch — the triage UI is downstream.
        unset($attrs['status']);
        $existing->fill($attrs)->save();

        return false;
    }

    /**
     * Per PRD §6.4.6: when a listing's parsed company name matches an
     * existing tracked Company (Jaro-Winkler ≥ 0.85), link the listing and
     * emit a NEW_ROLE event on the matched Company so the dashboard surfaces
     * it without the user having to triage. role_id is null — there's no
     * Role yet; the listing carries the structured detail.
     */
    private function attemptAutoLink(AggregatorListing $listing, ParsedListing $parsed): void
    {
        $companyName = $this->asString($parsed->value('company_name'));
        if ($companyName === null) {
            return;
        }

        $match = $this->matcher->match($companyName);
        if (! $match instanceof Company) {
            return;
        }

        $listing->matched_company_id = $match->id;
        $listing->status = AggregatorListingStatus::LINKED;
        $listing->save();

        $title = $this->asString($parsed->value('role_title')) ?? '(unknown role)';
        $body = (string) json_encode([
            'aggregator_listing_id' => $listing->id,
            'source' => $listing->source_id,
        ]);

        Event::query()->create([
            'company_id' => $match->id,
            'role_id' => null,
            'type' => EventType::NEW_ROLE->value,
            'title' => $title,
            'body' => $body,
            'occurred_at' => $listing->posted_at ?? CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @return array<string, mixed>
     */
    private function buildAttributes(
        AggregatorSource $source,
        SourceContext $context,
        ParsedListing $parsed,
        array $rawRecord,
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
            'raw' => $rawRecord,
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
