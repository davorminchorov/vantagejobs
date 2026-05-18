<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorListing;
use App\Models\DismissedCompany;

/**
 * Result of one DismissListingAction invocation.
 *
 *   listing                 — the (now-dismissed) AggregatorListing.
 *   rule                    — the DismissedCompany row, after the bump.
 *                             Null when the listing had no parsed company
 *                             name to key off.
 *   ruleFlippedToAutoDismiss — true if THIS dismiss caused auto_dismiss to
 *                             flip from false → true (i.e. crossed the
 *                             threshold). The UI uses this to show a
 *                             one-time toast on the third dismissal.
 *   alreadyDismissed        — true if the listing was already in
 *                             DISMISSED state and we no-op'd. Lets the UI
 *                             distinguish "first dismiss" from a stale
 *                             button click.
 */
final readonly class DismissResult
{
    public function __construct(
        public AggregatorListing $listing,
        public ?DismissedCompany $rule,
        public bool $ruleFlippedToAutoDismiss,
        public bool $alreadyDismissed,
    ) {}
}
