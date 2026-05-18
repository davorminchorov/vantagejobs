<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;

/**
 * Marks an AggregatorListing as KEPT — removed from the triage queue but
 * still visible under the "kept" filter, distinct from the bookmarks pile.
 *
 * The richer "Keep -> link to existing tracked company OR create new
 * Company from listing" flow described in PRD §6.4.3 needs a Company
 * autocomplete + modal; that's deferred to Phase 5 (Pipeline). For Phase 2,
 * Keep is a clean status flip — it gets the listing out of the queue so I
 * can move on.
 *
 * Idempotent: keeping an already-KEPT listing is a no-op.
 */
final readonly class KeepListingAction
{
    public function keep(AggregatorListing $listing): void
    {
        if ($listing->status === AggregatorListingStatus::KEPT) {
            return;
        }

        $listing->status = AggregatorListingStatus::KEPT;
        $listing->save();
    }
}
