<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorListing;
use App\Models\Bookmark;
use Carbon\CarbonImmutable;

/**
 * "Watch later" pile per PRD §6.6.2 — distinct from the application
 * pipeline. Bookmarks auto-expire after 30 days by default; the warn
 * + delete jobs (bookmarks:warn-expiring, bookmarks:expire) handle
 * the lifecycle.
 *
 * Idempotent: bookmarking an already-bookmarked listing returns the
 * existing row (re-bookmark resets `expires_warned_at` to null and
 * extends `expires_at`, treating it as a fresh add).
 */
final readonly class BookmarkListingAction
{
    public const DEFAULT_DAYS = 30;

    public function bookmark(AggregatorListing $listing, int $days = self::DEFAULT_DAYS): Bookmark
    {
        $existing = Bookmark::query()
            ->where('bookmarkable_type', 'aggregator_listing')
            ->where('bookmarkable_id', $listing->id)
            ->first();

        $expiresAt = CarbonImmutable::now()->addDays(max(1, $days));

        if ($existing instanceof Bookmark) {
            $existing->fill([
                'expires_at' => $expiresAt,
                'expires_warned_at' => null,
            ])->save();

            return $existing;
        }

        /** @var Bookmark $bookmark */
        $bookmark = Bookmark::query()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listing->id,
            'expires_at' => $expiresAt,
        ]);

        return $bookmark;
    }
}
