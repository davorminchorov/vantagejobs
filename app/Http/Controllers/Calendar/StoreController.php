<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarFeedCacheKey;
use App\Enums\CalendarEventType;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\RedirectResponse;

final readonly class StoreController
{
    public function __construct(private CacheRepository $cache) {}

    public function __invoke(StoreCalendarEventRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $timezone = (string) $data['timezone'];

        // Canonical storage is UTC: the user enters wall-clock time in their
        // chosen zone, we convert to UTC for starts_at and keep the original
        // IANA zone so the UI and iCal TZID can reconstruct local time.
        $startsAt = CarbonImmutable::parse((string) $data['starts_at'], $timezone)->utc();

        $reminderMinutes = array_key_exists('reminder_minutes', $data) && $data['reminder_minutes'] !== null
            ? (int) $data['reminder_minutes']
            : null;

        $event = CalendarEvent::query()->create([
            'owner_type' => $data['owner_type'] ?? null,
            'owner_id' => isset($data['owner_id']) && $data['owner_id'] !== null ? (int) $data['owner_id'] : null,
            'title' => (string) $data['title'],
            'starts_at' => $startsAt,
            'timezone' => $timezone,
            'type' => CalendarEventType::from((string) $data['type']),
            'notes' => $data['notes'] ?? null,
            'url' => $data['url'] ?? null,
            'reminder_minutes' => $reminderMinutes,
            'reminder_sent_at' => null,
        ]);

        $this->forgetFeedCache($request);

        return redirect()
            ->route('calendar.index')
            ->with('success', "Added \"{$event->title}\" to your calendar.");
    }

    private function forgetFeedCache(StoreCalendarEventRequest $request): void
    {
        $key = CalendarFeedCacheKey::forUser($request->user());

        if ($key !== null) {
            $this->cache->forget($key);
        }
    }
}
