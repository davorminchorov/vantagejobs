<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Models\User;

/**
 * Single source of truth for the public iCal feed cache key.
 *
 * The feed is served from an unauthenticated route keyed by the user's
 * calendar_feed_token, so the cache is keyed by that same token. Both the
 * feed controller (which writes the cache) and every calendar write
 * controller (which forgets it) resolve the key through this class so the two
 * sides can never drift apart.
 */
final readonly class CalendarFeedCacheKey
{
    private const PREFIX = 'calendar-feed:';

    public static function forToken(string $token): string
    {
        return self::PREFIX.$token;
    }

    /**
     * Resolve the cache key for a user, or null when the user has no feed
     * token yet (nothing to forget).
     */
    public static function forUser(?User $user): ?string
    {
        $token = $user?->getAttribute('calendar_feed_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        return self::forToken($token);
    }
}
