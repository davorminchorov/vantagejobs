<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Calendar\CalendarFeedCacheKey;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the per-user iCal feed subscription URL. The feed itself is served
 * by an unauthenticated, token-keyed route; this controller lets the user view
 * their current subscription URL and rotate the token (which immediately
 * invalidates the old URL and its cached body).
 */
final readonly class CalendarTokenController
{
    public function __construct(
        private CacheRepository $cache,
        private UrlGenerator $url,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $token = $user?->getAttribute('calendar_feed_token');

        return Inertia::render('Settings/Calendar', [
            'feedUrl' => is_string($token) && $token !== ''
                ? $this->url->route('calendar.feed', ['token' => $token])
                : null,
        ]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $previousKey = CalendarFeedCacheKey::forUser($user);

        $user->forceFill([
            'calendar_feed_token' => Str::random(40),
        ])->save();

        if ($previousKey !== null) {
            $this->cache->forget($previousKey);
        }

        return redirect()
            ->route('settings.calendar')
            ->with('success', 'Your calendar feed URL has been regenerated. The previous URL no longer works.');
    }
}
