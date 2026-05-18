<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for AggregatorListing.
 *
 * Transitions:
 *   new -> kept | dismissed | snoozed | linked | duplicate
 *   snoozed -> new (when snoozed_until elapses) | kept | dismissed
 *   overflow -> new (manual promotion) | dismissed
 *   auto_dismissed -> dismissed (auto-bulk) | new (if rule revoked)
 *   linked -> kept (manual override)
 */
enum AggregatorListingStatus: string
{
    case NEW = 'new';
    case LINKED = 'linked';
    case AUTO_DISMISSED = 'auto_dismissed';
    case OVERFLOW = 'overflow';
    case DUPLICATE = 'duplicate';
    case KEPT = 'kept';
    case DISMISSED = 'dismissed';
    case SNOOZED = 'snoozed';
}
