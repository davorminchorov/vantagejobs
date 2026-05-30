<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the current tenant's calendar events starting within the next N days.
 *
 * The global tenant scope on CalendarEvent already constrains rows to the
 * active tenant, so this query only adds the time window and ordering. Used by
 * the dashboard upcoming-events widget (default 7-day horizon) and reusable by
 * any caller that needs the near-term calendar.
 */
final readonly class UpcomingEventsQuery
{
    /**
     * Events whose starts_at falls between now and now + $days, ordered
     * soonest-first. Events already in the past are excluded.
     *
     * @return Collection<int, CalendarEvent>
     */
    public function forNextDays(int $days = 7, int $limit = 50): Collection
    {
        $now = CarbonImmutable::now();

        return CalendarEvent::query()
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $now->addDays($days))
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();
    }
}
