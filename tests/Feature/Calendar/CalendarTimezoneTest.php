<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Calendar\UpcomingEventsQuery;
use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalendarTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'calendar_feed_token' => 'tz-token',
        ]);
    }

    #[Test]
    public function store_converts_local_time_to_canonical_utc(): void
    {
        // 09:30 in New York on a summer date is EDT (UTC-4), so UTC is 13:30.
        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Morning standup',
                'starts_at' => '2026-06-10T09:30',
                'timezone' => 'America/New_York',
                'type' => CalendarEventType::INTERVIEW->value,
            ])
            ->assertRedirect();

        $event = CalendarEvent::query()->where('title', 'Morning standup')->sole();

        $this->assertSame('America/New_York', $event->timezone);
        $this->assertSame(
            '2026-06-10T13:30:00+00:00',
            $event->starts_at?->utc()->toIso8601String(),
        );
    }

    #[Test]
    public function display_offset_round_trips_back_to_the_original_wall_clock(): void
    {
        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'London call',
                'starts_at' => '2026-12-01T15:00',
                'timezone' => 'Europe/London',
                'type' => CalendarEventType::FOLLOW_UP->value,
            ])
            ->assertRedirect();

        $event = CalendarEvent::query()->where('title', 'London call')->sole();

        // December in London is GMT (UTC+0), so UTC equals the entered time.
        $this->assertSame(
            '2026-12-01T15:00:00+00:00',
            $event->starts_at?->utc()->toIso8601String(),
        );

        // Re-rendering the canonical UTC instant in the stored zone returns the
        // wall-clock time the user originally typed.
        $localWallClock = $event->starts_at
            ?->setTimezone($event->timezone)
            ->format('Y-m-d\TH:i');

        $this->assertSame('2026-12-01T15:00', $localWallClock);
    }

    #[Test]
    public function resource_emits_utc_iso_string_with_original_zone(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Wire shape',
            'starts_at' => CarbonImmutable::parse('2026-06-10T09:30', 'America/New_York')->utc(),
            'timezone' => 'America/New_York',
        ]);

        $this->actingAs($this->user)
            ->get('/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('events.data.0.starts_at', '2026-06-10T13:30:00+00:00')
                ->where('events.data.0.timezone', 'America/New_York'));
    }

    #[Test]
    public function upcoming_events_query_returns_next_seven_days_ordered(): void
    {
        $soon = CalendarEvent::factory()->create(['starts_at' => now()->addDay()]);
        $later = CalendarEvent::factory()->create(['starts_at' => now()->addDays(5)]);
        // Outside the 7-day horizon.
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(20)]);
        // Already past.
        CalendarEvent::factory()->create(['starts_at' => now()->subDay()]);

        $results = app(UpcomingEventsQuery::class)->forNextDays(7);

        $this->assertCount(2, $results);
        $this->assertSame($soon->id, $results->first()?->id);
        $this->assertSame($later->id, $results->last()?->id);
    }

    #[Test]
    public function upcoming_events_query_is_tenant_scoped(): void
    {
        CalendarEvent::factory()->create(['starts_at' => now()->addDay()]);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            CalendarEvent::factory()->create(['starts_at' => now()->addDay()]);
        });

        $results = app(UpcomingEventsQuery::class)->forNextDays(7);

        $this->assertCount(1, $results);
    }
}
