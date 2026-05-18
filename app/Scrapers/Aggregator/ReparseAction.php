<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorListing;
use App\Models\ParseCorrection;
use App\Scrapers\Aggregator\Parser\ParsedListing;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs the ParserPipeline against an AggregatorListing's stored raw
 * payload, then merges in the listing's ParseCorrection rows.
 *
 * Use cases:
 *   - parser improved since the listing was first ingested → re-parse to
 *     pick up new extractor logic.
 *   - user reverted a manual correction → drop the audit row and re-run
 *     to restore the parser's view of the field.
 *
 * Merge rule (default): corrections always win on conflict — the user
 * explicitly told us what the field is, so the parser's guess is
 * subordinate. Pass `force = true` to get the raw parser output without
 * the correction overlay.
 *
 * Idempotent: re-running with the same input produces the same DB state.
 */
final readonly class ReparseAction
{
    public function __construct(
        private SourceContextFactory $contextFactory,
        private ParserPipeline $pipeline,
    ) {}

    public function reparse(AggregatorListing $listing, bool $force = false): AggregatorListing
    {
        return DB::transaction(function () use ($listing, $force): AggregatorListing {
            $this->reparseWithoutTransaction($listing, $force);

            return $listing->fresh() ?? $listing;
        });
    }

    /**
     * Re-parse a single listing without opening its own transaction. Used by
     * bulk callers (parser:reparse-all) that wrap a whole chunk of listings
     * in one transaction to keep the DB friendlier under load.
     */
    public function reparseWithoutTransaction(AggregatorListing $listing, bool $force = false): AggregatorListing
    {
        $context = $this->contextFactory->buildFor($listing);
        $parsed = $this->pipeline->parse($context);

        $this->writeParsedToListing($listing, $parsed);
        $listing->save();

        if (! $force) {
            $this->overlayCorrections($listing);
        }

        return $listing;
    }

    /**
     * Project ParsedListing back into the listing's parsed jsonb column +
     * denormalized columns. Same shape AggregatorListingPersister writes
     * at ingest time.
     */
    private function writeParsedToListing(AggregatorListing $listing, ParsedListing $parsed): void
    {
        $compensation = $parsed->value('compensation');
        $remote = $parsed->value('remote');
        $stackTags = $parsed->value('stack_tags');

        $minUsd = is_array($compensation) && isset($compensation['min_usd']) ? $compensation['min_usd'] : null;
        $maxUsd = is_array($compensation) && isset($compensation['max_usd']) ? $compensation['max_usd'] : null;

        $listing->parsed = $parsed->toArray();
        $listing->parsed_company_name = $this->asString($parsed->value('company_name'));
        $listing->parsed_role_title = $this->asString($parsed->value('role_title'));
        $listing->parsed_seniority = $this->asString($parsed->value('seniority'));
        $listing->parsed_remote_type = is_array($remote) ? ($remote['type'] ?? null) : null;
        $listing->parsed_comp_min_usd = is_int($minUsd) ? $minUsd : null;
        $listing->parsed_comp_max_usd = is_int($maxUsd) ? $maxUsd : null;
        $listing->has_comp = $listing->parsed_comp_min_usd !== null || $listing->parsed_comp_max_usd !== null;
        $listing->stack_tags = is_array($stackTags) ? array_values($stackTags) : null;
        $listing->location_bucket = $this->asString($parsed->value('region'));
    }

    /**
     * Overlay every existing ParseCorrection on the freshly-parsed state.
     * Each correction wins on conflict: its value goes back into the
     * parsed jsonb slot and the matching denormalized column, with the
     * standard "explicit / user_correction" slot metadata.
     *
     * Existing audit rows are preserved unchanged -- a re-parse is not
     * a new correction event.
     */
    private function overlayCorrections(AggregatorListing $listing): void
    {
        $corrections = ParseCorrection::query()
            ->where('listing_id', $listing->id)
            ->orderBy('created_at')
            ->get();

        if ($corrections->isEmpty()) {
            return;
        }

        $parsed = $listing->parsed ?? [];
        foreach ($corrections as $correction) {
            $newValuePayload = is_array($correction->new_value) ? $correction->new_value : [];
            $newValue = $newValuePayload['value'] ?? null;
            $field = (string) $correction->field;

            $slotKey = $this->parsedSlotKey($field);
            $existingSlot = $parsed[$slotKey] ?? null;
            $existingValue = is_array($existingSlot) ? ($existingSlot['value'] ?? null) : null;

            $parsed[$slotKey] = [
                'value' => $this->mergeIntoSlotValue($field, $existingValue, $newValue),
                'confidence' => 1.0,
                'source' => 'explicit',
                'evidence' => 'user_correction',
            ];

            $this->syncDenormalizedColumn($listing, $field, $newValue);
        }
        $listing->parsed = $parsed;
        $listing->save();
    }

    private function parsedSlotKey(string $field): string
    {
        return match ($field) {
            'remote_type' => 'remote',
            'comp_min_usd', 'comp_max_usd' => 'compensation',
            default => $field,
        };
    }

    private function mergeIntoSlotValue(string $field, mixed $oldValue, mixed $newValue): mixed
    {
        return match ($field) {
            'remote_type' => array_merge(
                is_array($oldValue) ? $oldValue : [],
                ['type' => $newValue],
            ),
            'comp_min_usd' => array_merge(
                is_array($oldValue) ? $oldValue : [],
                ['min_usd' => $newValue],
            ),
            'comp_max_usd' => array_merge(
                is_array($oldValue) ? $oldValue : [],
                ['max_usd' => $newValue],
            ),
            default => $newValue,
        };
    }

    private function syncDenormalizedColumn(AggregatorListing $listing, string $field, mixed $newValue): void
    {
        match ($field) {
            'company_name' => $listing->parsed_company_name = is_string($newValue) ? $newValue : null,
            'role_title' => $listing->parsed_role_title = is_string($newValue) ? $newValue : null,
            'seniority' => $listing->parsed_seniority = is_string($newValue) ? $newValue : null,
            'remote_type' => $listing->parsed_remote_type = is_string($newValue) ? $newValue : null,
            'region' => $listing->location_bucket = is_string($newValue) ? $newValue : null,
            'comp_min_usd' => $listing->parsed_comp_min_usd = is_int($newValue) ? $newValue : null,
            'comp_max_usd' => $listing->parsed_comp_max_usd = is_int($newValue) ? $newValue : null,
            'stack_tags' => $listing->stack_tags = is_array($newValue) ? array_values($newValue) : null,
            default => null,
        };

        if ($field === 'comp_min_usd' || $field === 'comp_max_usd') {
            $listing->has_comp = $listing->parsed_comp_min_usd !== null || $listing->parsed_comp_max_usd !== null;
        }
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
