<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarFeedCacheKey;
use App\Models\CalendarEvent;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class DestroyController
{
    public function __construct(private CacheRepository $cache) {}

    public function __invoke(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $title = $calendarEvent->title;

        $calendarEvent->delete();

        $key = CalendarFeedCacheKey::forUser($request->user());
        if ($key !== null) {
            $this->cache->forget($key);
        }

        return redirect()
            ->route('calendar.index')
            ->with('success', "Removed \"{$title}\" from your calendar.");
    }
}
