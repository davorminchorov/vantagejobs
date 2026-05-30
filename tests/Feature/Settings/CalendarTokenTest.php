<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Calendar\CalendarFeedCacheKey;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalendarTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create(['slug' => 'calendar-token-test']);
        app(TenantContext::class)->setCurrent($tenant);

        $this->user = User::factory()->create([
            'calendar_feed_token' => Str::random(40),
        ]);
    }

    #[Test]
    public function the_calendar_settings_page_renders_for_authenticated_users(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Calendar')
                ->where('feedUrl', fn ($url): bool => is_string($url) && str_contains($url, 'feed.ics'))
            );
    }

    #[Test]
    public function guests_cannot_view_the_calendar_settings_page(): void
    {
        $this->get('/settings/calendar')->assertRedirect('/login');
    }

    #[Test]
    public function regenerating_replaces_the_token_with_a_new_40_character_value(): void
    {
        $previous = (string) $this->user->getAttribute('calendar_feed_token');

        $this->actingAs($this->user)
            ->post('/settings/calendar/regenerate')
            ->assertRedirect('/settings/calendar')
            ->assertSessionHas('success');

        $this->user->refresh();
        $current = (string) $this->user->getAttribute('calendar_feed_token');

        $this->assertNotSame($previous, $current);
        $this->assertSame(40, strlen($current));
    }

    #[Test]
    public function guests_cannot_regenerate_the_token(): void
    {
        $this->post('/settings/calendar/regenerate')->assertRedirect('/login');
    }

    #[Test]
    public function regenerating_forgets_the_old_tokens_cached_feed(): void
    {
        $oldToken = (string) $this->user->getAttribute('calendar_feed_token');

        CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        // Warm the cache for the old token.
        $this->get("/calendar/{$oldToken}/feed.ics")->assertOk();
        $cache = app(CacheRepository::class);
        $this->assertNotNull($cache->get(CalendarFeedCacheKey::forToken($oldToken)));

        $this->actingAs($this->user)
            ->post('/settings/calendar/regenerate')
            ->assertRedirect('/settings/calendar');

        $this->assertNull($cache->get(CalendarFeedCacheKey::forToken($oldToken)));
    }

    #[Test]
    public function the_old_feed_url_stops_working_after_regeneration(): void
    {
        $oldToken = (string) $this->user->getAttribute('calendar_feed_token');

        $this->actingAs($this->user)
            ->post('/settings/calendar/regenerate')
            ->assertRedirect('/settings/calendar');

        $this->get("/calendar/{$oldToken}/feed.ics")->assertNotFound();
    }
}
