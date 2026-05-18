<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Models\DismissedCompany;
use Carbon\CarbonImmutable;

/**
 * Dismisses one listing and updates the per-employer dismiss memory.
 *
 * Per PRD §6.4.4:
 *   - On every manual dismissal, bump dismiss_count for the normalized
 *     company key (lazily creating the row).
 *   - On the third consecutive manual dismissal, flip auto_dismiss = true.
 *     Future listings from that employer will arrive auto-dismissed.
 *
 * Idempotent: re-dismissing the same listing (already DISMISSED) does not
 * bump the count again. The count is per-listing-dismiss-event, not per
 * listing.
 */
final readonly class DismissListingAction
{
    public const AUTO_DISMISS_THRESHOLD = 3;

    public function __construct(private CompanyKeyNormalizer $normalizer) {}

    public function dismiss(AggregatorListing $listing): DismissResult
    {
        if ($listing->status === AggregatorListingStatus::DISMISSED) {
            return new DismissResult(
                listing: $listing,
                rule: $this->existingRuleFor($listing),
                ruleFlippedToAutoDismiss: false,
                alreadyDismissed: true,
            );
        }

        $listing->status = AggregatorListingStatus::DISMISSED;
        $listing->save();

        $key = $this->normalizer->normalize($listing->parsed_company_name);
        if ($key === null) {
            return new DismissResult(
                listing: $listing,
                rule: null,
                ruleFlippedToAutoDismiss: false,
                alreadyDismissed: false,
            );
        }

        $now = CarbonImmutable::now();
        $existing = DismissedCompany::query()
            ->where('normalized_company_key', $key)
            ->first();

        if ($existing === null) {
            /** @var DismissedCompany $rule */
            $rule = DismissedCompany::query()->create([
                'normalized_company_key' => $key,
                'display_name' => $listing->parsed_company_name ?? $key,
                'dismiss_count' => 1,
                'auto_dismiss' => false,
                'first_dismissed_at' => $now,
                'last_dismissed_at' => $now,
            ]);

            return new DismissResult(
                listing: $listing,
                rule: $rule,
                ruleFlippedToAutoDismiss: false,
                alreadyDismissed: false,
            );
        }

        $existing->dismiss_count = $existing->dismiss_count + 1;
        $existing->last_dismissed_at = $now;
        $flipped = false;
        if (! $existing->auto_dismiss && $existing->dismiss_count >= self::AUTO_DISMISS_THRESHOLD) {
            $existing->auto_dismiss = true;
            $flipped = true;
        }
        $existing->save();

        return new DismissResult(
            listing: $listing,
            rule: $existing,
            ruleFlippedToAutoDismiss: $flipped,
            alreadyDismissed: false,
        );
    }

    private function existingRuleFor(AggregatorListing $listing): ?DismissedCompany
    {
        $key = $this->normalizer->normalize($listing->parsed_company_name);
        if ($key === null) {
            return null;
        }

        return DismissedCompany::query()->where('normalized_company_key', $key)->first();
    }
}
