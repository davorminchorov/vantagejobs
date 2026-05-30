<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Selects calendar events whose reminder is due to fire but has not yet been
 * sent. The definition is deliberately a single SQL predicate so the sweep
 * command stays idempotent and survives missed scheduler ticks: any event that
 * crossed its reminder threshold while the worker was down is still picked up
 * on the next run.
 *
 * An event is due when all hold:
 *  - reminder_minutes is set (NULL means reminders are off for that event), AND
 *  - reminder_sent_at is NULL (never reminded), AND
 *  - starts_at is still in the future (we never fire reminders for events that
 *    already happened — a long outage should not blast stale reminders), AND
 *  - now() >= starts_at - reminder_minutes (the lead-time window has opened).
 *
 * All reads run through the Eloquent global tenant scope, so callers never pass
 * a tenant id; the active TenantContext bounds every query.
 */
final readonly class DueRemindersQuery
{
    /**
     * Hydrate the due events, soonest-first, so the most imminent reminders go
     * out before later ones if the run is throttled.
     *
     * @return Collection<int, CalendarEvent>
     */
    public function get(?CarbonImmutable $now = null, ?int $limit = null): Collection
    {
        $query = $this->baseQuery($now)->orderBy('starts_at')->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Number of events currently due for a reminder. Cheap count without
     * hydrating rows.
     */
    public function count(?CarbonImmutable $now = null): int
    {
        return $this->baseQuery($now)->count();
    }

    /**
     * Base builder with the due-reminder predicate applied. Exposed as an
     * Eloquent builder so callers can chunk, count, or further constrain.
     *
     * @return Builder<CalendarEvent>
     */
    public function baseQuery(?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return CalendarEvent::query()
            ->whereNotNull('reminder_minutes')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->whereRaw(
                "? >= starts_at - (reminder_minutes * interval '1 minute')",
                [$now]
            );
    }
}
