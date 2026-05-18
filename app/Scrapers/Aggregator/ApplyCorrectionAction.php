<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorListing;
use App\Models\ParseCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;

/**
 * Applies an inline user correction to an AggregatorListing.
 *
 * Per PRD §6.1.5 + §6.4.2: when a user edits a parsed field on a Discover
 * card, we (a) write a ParseCorrection row capturing the prior value as an
 * audit trail, (b) update the parsed jsonb slot to a high-confidence
 * explicit + "user_correction" evidence record, and (c) sync the
 * denormalized column(s) the triage UI reads from.
 *
 * Idempotent: if `$newValue` is identical to the current value at the
 * parsed slot, no row is written and no columns mutate. This lets the UI
 * fire the same POST on every save click without polluting the audit log.
 *
 * The set of editable fields is fixed and intentionally narrow — the
 * triage card surfaces company / role / seniority / remote / region /
 * comp / stack and nothing else, so corrections target the same surface.
 */
final readonly class ApplyCorrectionAction
{
    /**
     * Allowed user-facing field keys. Keep in sync with
     * SaveCorrectionRequest::rulesForField().
     */
    public const FIELDS = [
        'company_name',
        'role_title',
        'seniority',
        'remote_type',
        'region',
        'comp_min_usd',
        'comp_max_usd',
        'stack_tags',
    ];

    public function __construct(private AuthFactory $auth) {}

    /**
     * Apply a correction. Returns the ParseCorrection row when one was
     * written, or null when the call was a no-op (value unchanged).
     */
    public function apply(AggregatorListing $listing, string $field, mixed $newValue): ?ParseCorrection
    {
        if (! in_array($field, self::FIELDS, true)) {
            return null;
        }

        $currentValue = $this->currentUserFacingValue($listing, $field);
        if ($this->valuesEqual($currentValue, $newValue)) {
            return null;
        }

        $parsedSlotKey = $this->parsedSlotKey($field);
        $parsed = $listing->parsed ?? [];
        $oldSlot = $parsed[$parsedSlotKey] ?? null;

        return DB::transaction(function () use ($listing, $field, $newValue, $parsedSlotKey, $parsed, $oldSlot): ParseCorrection {
            /** @var ParseCorrection $correction */
            $correction = ParseCorrection::query()->create([
                'listing_id' => $listing->id,
                'user_id' => $this->auth->guard()->id(),
                'field' => $field,
                'old_value' => $oldSlot === null ? null : ['value' => $this->oldUserFacingValue($field, $oldSlot)],
                'old_confidence' => is_array($oldSlot) && isset($oldSlot['confidence']) ? (float) $oldSlot['confidence'] : null,
                'old_source' => is_array($oldSlot) && isset($oldSlot['source']) ? (string) $oldSlot['source'] : null,
                'new_value' => ['value' => $newValue],
                'created_at' => CarbonImmutable::now(),
            ]);

            $mergedSlotValue = $this->mergeIntoSlotValue($field, $oldSlot, $newValue);

            $parsed[$parsedSlotKey] = [
                'value' => $mergedSlotValue,
                'confidence' => 1.0,
                'source' => 'explicit',
                'evidence' => 'user_correction',
            ];
            $listing->parsed = $parsed;

            $this->syncDenormalizedColumns($listing, $field, $newValue);

            $listing->save();

            return $correction;
        });
    }

    /**
     * The current value as it would be edited by the user (string for
     * company_name, integer for comp_min_usd, etc.).
     */
    private function currentUserFacingValue(AggregatorListing $listing, string $field): mixed
    {
        $slot = $listing->parsedField($this->parsedSlotKey($field));
        if ($slot === null) {
            return null;
        }

        return $this->oldUserFacingValue($field, $slot);
    }

    /**
     * @param  array<string, mixed>|null  $slot
     */
    private function oldUserFacingValue(string $field, ?array $slot): mixed
    {
        if ($slot === null) {
            return null;
        }
        $value = $slot['value'] ?? null;

        return match ($field) {
            'remote_type' => is_array($value) ? ($value['type'] ?? null) : null,
            'comp_min_usd' => is_array($value) ? ($value['min_usd'] ?? null) : null,
            'comp_max_usd' => is_array($value) ? ($value['max_usd'] ?? null) : null,
            default => $value,
        };
    }

    /**
     * Key in the parsed jsonb column. remote_type and comp_*_usd live
     * inside compound slots, so the slot key differs from the user-facing
     * field name.
     */
    private function parsedSlotKey(string $field): string
    {
        return match ($field) {
            'remote_type' => 'remote',
            'comp_min_usd', 'comp_max_usd' => 'compensation',
            default => $field,
        };
    }

    /**
     * Build the new `value` payload for the parsed slot. Compound slots
     * preserve sibling sub-keys from the existing slot when present.
     *
     * @param  array<string, mixed>|null  $oldSlot
     */
    private function mergeIntoSlotValue(string $field, ?array $oldSlot, mixed $newValue): mixed
    {
        $oldValue = is_array($oldSlot) ? ($oldSlot['value'] ?? null) : null;

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

    private function syncDenormalizedColumns(AggregatorListing $listing, string $field, mixed $newValue): void
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

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return $a === $b;
        }

        return $a === $b;
    }
}
