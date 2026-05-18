<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\DismissedCompany;
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
 * filter + context-building; this service owns the parse-and-persist tail
 * plus the ingestion-time gates:
 *
 *   1. AUTO_DISMISSED  — employer is on the auto-dismiss list (step 13).
 *   2. DUPLICATE       — same (company, role, location) seen in the
 *                        last 14 days (step 14, PRD §6.4.7).
 *   3. LINKED          — fuzzy-matches an existing tracked Company
 *                        (step 12, PRD §6.4.6).
 *   4. NEW             — fall-through.
 *
 * Auto-link is skipped for AUTO_DISMISSED and DUPLICATE listings — they're
 * already off the triage queue, no point emitting a NEW_ROLE Event.
 */
final readonly class AggregatorListingPersister
{
    /**
     * Cross-source dedup window per PRD §6.4.7.
     */
    private const DEDUP_WINDOW_DAYS = 14;

    /**
     * Boomerang re-post gap per PRD §6.3.7.
     */
    private const BOOMERANG_GAP_DAYS = 14;

    public function __construct(
        private ParserPipeline $pipeline,
        private CompanyKeyNormalizer $normalizer,
        private MatchCompanyAction $matcher,
        private VolumeCapAction $volumeCap,
        private RoleHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $rawRecord
     * @return int|null  ID of the newly-inserted listing, or null when an
     *                   existing row was updated. Scrapers accumulate the
     *                   returned IDs and pass them to capRun() at end-of-run.
     */
    public function upsert(
        AggregatorSource $source,
        SourceContext $context,
        array $rawRecord,
        string $externalId,
    ): ?int {
        if ($externalId === '') {
            return null;
        }

        $parsed = $this->pipeline->parse($context);

        $existing = AggregatorListing::query()
            ->where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        $attrs = $this->buildAttributes($source, $context, $parsed, $rawRecord, $externalId);

        if ($existing === null) {
            $attrs = $this->applyAutoDismissGate($attrs);
            $attrs = $this->applyDedupGate($attrs);

            /** @var AggregatorListing $listing */
            $listing = AggregatorListing::query()->create($attrs);

            if ($listing->status === AggregatorListingStatus::NEW) {
                $this->attemptAutoLink($listing, $parsed);
            }

            $this->detectBoomerang($listing);

            return (int) $listing->id;
        }

        // Preserve user-set status on re-fetch — the triage UI is downstream.
        unset($attrs['status']);
        $existing->fill($attrs)->save();

        return null;
    }

    /**
     * Demote sub-50 NEW listings from `$createdIds` to OVERFLOW when the
     * run produced more than the trigger threshold of new listings, per
     * PRD §6.4.5. Returns count demoted.
     *
     * @param  list<int>  $createdIds
     */
    public function capRun(array $createdIds): int
    {
        return $this->volumeCap->cap($createdIds);
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
     * Per PRD §6.4.4: if the normalized company name has an active
     * auto-dismiss rule, the listing is created with status AUTO_DISMISSED
     * instead of NEW so it bypasses the triage queue. Visible under the
     * "auto-dismissed" filter so the user can audit / revoke.
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function applyAutoDismissGate(array $attrs): array
    {
        $key = $attrs['normalized_company_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return $attrs;
        }

        $rule = DismissedCompany::query()
            ->where('normalized_company_key', $key)
            ->where('auto_dismiss', true)
            ->first();

        if ($rule !== null) {
            $attrs['status'] = AggregatorListingStatus::AUTO_DISMISSED->value;
        }

        return $attrs;
    }

    /**
     * Per PRD §6.4.7: within a 14-day window, listings sharing the same
     * (normalized_company_key, normalized_role_key, location_bucket) are
     * marked as duplicates of the earliest visible match. Already-
     * dismissed / auto-dismissed / duplicate rows are not candidates —
     * if the user has actively hidden a listing, surfacing the same role
     * from a different source as fresh-NEW is the desired behaviour.
     *
     * Skips dedup entirely when any of the three keys is null — we cannot
     * collide what we cannot key on.
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function applyDedupGate(array $attrs): array
    {
        if (($attrs['status'] ?? null) === AggregatorListingStatus::AUTO_DISMISSED->value) {
            // Auto-dismiss already won; no need to also flag duplicate.
            return $attrs;
        }

        $companyKey = $attrs['normalized_company_key'] ?? null;
        $roleKey = $attrs['normalized_role_key'] ?? null;
        $locationBucket = $attrs['location_bucket'] ?? null;
        if (! is_string($companyKey) || ! is_string($roleKey) || ! is_string($locationBucket)) {
            return $attrs;
        }
        if ($companyKey === '' || $roleKey === '' || $locationBucket === '') {
            return $attrs;
        }

        $window = CarbonImmutable::now()->subDays(self::DEDUP_WINDOW_DAYS);

        $canonical = AggregatorListing::query()
            ->where('normalized_company_key', $companyKey)
            ->where('normalized_role_key', $roleKey)
            ->where('location_bucket', $locationBucket)
            ->whereNotIn('status', [
                AggregatorListingStatus::DISMISSED->value,
                AggregatorListingStatus::AUTO_DISMISSED->value,
                AggregatorListingStatus::DUPLICATE->value,
            ])
            ->where('fetched_at', '>=', $window)
            ->orderBy('fetched_at')
            ->first(['id']);

        if ($canonical !== null) {
            $attrs['status'] = AggregatorListingStatus::DUPLICATE->value;
            $attrs['duplicate_of_id'] = $canonical->id;
        }

        return $attrs;
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
            'normalized_company_key' => $companyKey = $this->normalizer->normalize($this->asString($parsed->value('company_name'))),
            'normalized_role_key' => $roleKey = $this->normalizer->normalize($this->asString($parsed->value('role_title'))),
            'location_bucket' => $locationBucket = $this->asString($parsed->value('region')),
            'role_hash' => $this->hasher->hash($companyKey, $roleKey, $locationBucket),
            'status' => AggregatorListingStatus::NEW->value,
            'posted_at' => $context->postedAt,
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Per PRD §6.3.7: when a fresh listing's role_hash matches a prior
     * listing that was last seen ≥ 14 days ago, mark it a boomerang and
     * emit an Event so the timeline shows the re-post. Skip when the
     * listing is already gated (duplicate/auto-dismissed) — those flags
     * win, no point cluttering the timeline.
     */
    private function detectBoomerang(AggregatorListing $listing): void
    {
        if (in_array($listing->status, [
            AggregatorListingStatus::DUPLICATE,
            AggregatorListingStatus::AUTO_DISMISSED,
        ], true)) {
            return;
        }

        if (! $this->hasher->isComplete(
            $listing->normalized_company_key,
            $listing->normalized_role_key,
            $listing->location_bucket,
        )) {
            return;
        }

        $threshold = CarbonImmutable::now()->subDays(self::BOOMERANG_GAP_DAYS);

        $priorMaxFetched = AggregatorListing::query()
            ->where('id', '!=', $listing->id)
            ->where('role_hash', $listing->role_hash)
            ->whereNotIn('status', [
                AggregatorListingStatus::DUPLICATE->value,
                AggregatorListingStatus::AUTO_DISMISSED->value,
            ])
            ->max('fetched_at');

        if ($priorMaxFetched === null) {
            return;
        }

        $priorMaxFetched = $priorMaxFetched instanceof CarbonImmutable
            ? $priorMaxFetched
            : CarbonImmutable::parse((string) $priorMaxFetched);

        if ($priorMaxFetched->greaterThan($threshold)) {
            // Prior sighting is inside the 14-day window; not a boomerang.
            return;
        }

        $companyId = $listing->matched_company_id;
        if ($companyId === null) {
            // No tracked company to hang the timeline event off. We still
            // count it as a boomerang via the role_hash join later; we just
            // can't write a per-company Event row here.
            return;
        }

        Event::query()->create([
            'company_id' => $companyId,
            'role_id' => null,
            'type' => EventType::BOOMERANG->value,
            'title' => 'Role re-posted after '.$priorMaxFetched->diffInDays(CarbonImmutable::now()).' days',
            'body' => (string) json_encode([
                'aggregator_listing_id' => $listing->id,
                'role_hash' => $listing->role_hash,
                'previously_seen_at' => $priorMaxFetched->toIso8601String(),
            ]),
            'occurred_at' => $listing->fetched_at ?? CarbonImmutable::now(),
        ]);
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
