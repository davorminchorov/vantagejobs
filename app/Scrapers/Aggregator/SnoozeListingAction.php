<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use Carbon\CarbonImmutable;

/**
 * Hides a listing from the triage queue until `snoozed_until` elapses.
 * Default snooze is 7 days per the kickoff. UI offers +3 / +7 / +14 / +30.
 *
 * When the elapsed-snooze sweep runs (step 16), SNOOZED listings flip back
 * to NEW. Step 15 only owns the snooze write.
 */
final readonly class SnoozeListingAction
{
    public const DEFAULT_DAYS = 7;

    public function snooze(AggregatorListing $listing, int $days = self::DEFAULT_DAYS): void
    {
        $listing->status = AggregatorListingStatus::SNOOZED;
        $listing->snoozed_until = CarbonImmutable::now()->addDays(max(1, $days));
        $listing->save();
    }
}
