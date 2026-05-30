<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Calendar\CalendarFeedCacheKey;
use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalendarEventCrudTest extends TestCase
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
            'calendar_feed_token' => 'feed-token-abc',
        ]);
    }

    #[Test]
    public function index_page_renders_upcoming_events(): void
    {
        CalendarEvent::factory()->count(2)->create([
            'starts_at' => now()->addDays(2),
        ]);
        // A past event must not appear in the default (upcoming) view.
        CalendarEvent::factory()->create([
            'starts_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->user)
            ->get('/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Calendar/Index')
                ->has('events.data', 2)
                ->has('options.types')
                ->has('options.owner_types')
                ->where('filters.past', false));
    }

    #[Test]
    public function index_can_include_past_events(): void
    {
        CalendarEvent::factory()->create(['starts_at' => now()->subDays(2)]);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(2)]);

        $this->actingAs($this->user)
            ->get('/calendar?past=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 2)
                ->where('filters.past', true));
    }

    #[Test]
    public function index_requires_authentication(): void
    {
        $this->get('/calendar')->assertRedirect('/login');
    }

    #[Test]
    public function store_creates_a_standalone_event(): void
    {
        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Coffee chat',
                'starts_at' => '2026-06-10T09:30',
                'timezone' => 'America/New_York',
                'type' => CalendarEventType::FOLLOW_UP->value,
                'reminder_minutes' => 15,
            ])
            ->assertRedirect(route('calendar.index'));

        $event = CalendarEvent::query()->where('title', 'Coffee chat')->sole();
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertSame(CalendarEventType::FOLLOW_UP, $event->type);
        $this->assertSame(15, $event->reminder_minutes);
        $this->assertNull($event->owner_type);
        $this->assertNull($event->owner_id);
        $this->assertNull($event->reminder_sent_at);
    }

    #[Test]
    public function store_attaches_an_event_to_an_owner(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Onsite',
                'starts_at' => '2026-06-12T13:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::INTERVIEW->value,
                'owner_type' => 'role',
                'owner_id' => $role->id,
            ])
            ->assertRedirect();

        $event = CalendarEvent::query()->where('title', 'Onsite')->sole();
        $this->assertSame('role', $event->owner_type);
        $this->assertSame($role->id, (int) $event->owner_id);
    }

    #[Test]
    public function store_rejects_an_owner_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignRole = app(TenantContext::class)->withTenant(
            $otherTenant,
            function () {
                $company = Company::factory()->create();

                return Role::factory()->create(['company_id' => $company->id]);
            },
        );

        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Leaky',
                'starts_at' => '2026-06-12T13:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::INTERVIEW->value,
                'owner_type' => 'role',
                'owner_id' => $foreignRole->id,
            ])
            ->assertSessionHasErrors('owner_id');

        $this->assertDatabaseMissing('calendar_events', ['title' => 'Leaky']);
    }

    #[Test]
    public function store_rejects_an_unknown_owner_type(): void
    {
        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Bad owner',
                'starts_at' => '2026-06-12T13:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::INTERVIEW->value,
                'owner_type' => 'aggregator_listing',
                'owner_id' => 1,
            ])
            ->assertSessionHasErrors('owner_type');
    }

    #[Test]
    public function store_requires_owner_id_when_owner_type_is_present(): void
    {
        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Missing id',
                'starts_at' => '2026-06-12T13:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::INTERVIEW->value,
                'owner_type' => 'role',
            ])
            ->assertSessionHasErrors('owner_id');
    }

    #[Test]
    public function store_rejects_an_invalid_timezone(): void
    {
        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Bad zone',
                'starts_at' => '2026-06-12T13:00',
                'timezone' => 'Mars/Olympus_Mons',
                'type' => CalendarEventType::INTERVIEW->value,
            ])
            ->assertSessionHasErrors('timezone');
    }

    #[Test]
    public function store_requires_a_title_and_start(): void
    {
        $this->actingAs($this->user)
            ->post('/calendar', [
                'timezone' => 'UTC',
                'type' => CalendarEventType::INTERVIEW->value,
            ])
            ->assertSessionHasErrors(['title', 'starts_at']);
    }

    #[Test]
    public function store_requires_authentication(): void
    {
        $this->post('/calendar', [
            'title' => 'Nope',
            'starts_at' => '2026-06-12T13:00',
            'timezone' => 'UTC',
            'type' => CalendarEventType::INTERVIEW->value,
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('calendar_events', ['title' => 'Nope']);
    }

    #[Test]
    public function store_forgets_the_feed_cache(): void
    {
        $cache = app(CacheRepository::class);
        $key = CalendarFeedCacheKey::forToken('feed-token-abc');
        $cache->put($key, 'STALE', 300);

        $this->actingAs($this->user)
            ->post('/calendar', [
                'title' => 'Invalidate me',
                'starts_at' => '2026-06-12T13:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::INTERVIEW->value,
            ])
            ->assertRedirect();

        $this->assertNull($cache->get($key));
    }

    #[Test]
    public function update_persists_changes_and_forgets_cache(): void
    {
        $event = CalendarEvent::factory()->create([
            'title' => 'Old title',
            'timezone' => 'UTC',
        ]);

        $cache = app(CacheRepository::class);
        $key = CalendarFeedCacheKey::forToken('feed-token-abc');
        $cache->put($key, 'STALE', 300);

        $this->actingAs($this->user)
            ->put("/calendar/{$event->id}", [
                'title' => 'New title',
                'starts_at' => '2026-07-01T08:00',
                'timezone' => 'Europe/London',
                'type' => CalendarEventType::DEADLINE->value,
                'reminder_minutes' => 60,
            ])
            ->assertRedirect(route('calendar.index'));

        $fresh = $event->fresh();
        $this->assertSame('New title', $fresh->title);
        $this->assertSame('Europe/London', $fresh->timezone);
        $this->assertSame(CalendarEventType::DEADLINE, $fresh->type);
        $this->assertSame(60, $fresh->reminder_minutes);
        $this->assertNull($cache->get($key));
    }

    #[Test]
    public function update_can_clear_the_reminder(): void
    {
        $event = CalendarEvent::factory()->create(['reminder_minutes' => 30, 'timezone' => 'UTC']);

        $this->actingAs($this->user)
            ->put("/calendar/{$event->id}", [
                'title' => $event->title,
                'starts_at' => '2026-07-01T08:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::REMINDER->value,
                'reminder_minutes' => null,
            ])
            ->assertRedirect();

        $this->assertNull($event->fresh()->reminder_minutes);
    }

    #[Test]
    public function update_blocks_another_tenants_event(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => CalendarEvent::factory()->create(['title' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->put("/calendar/{$foreign->id}", [
                'title' => 'Hijack',
                'starts_at' => '2026-07-01T08:00',
                'timezone' => 'UTC',
                'type' => CalendarEventType::REMINDER->value,
            ])
            ->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertSame('Theirs', $foreign->fresh()->title);
        });
    }

    #[Test]
    public function destroy_removes_the_event_and_forgets_cache(): void
    {
        $event = CalendarEvent::factory()->create();

        $cache = app(CacheRepository::class);
        $key = CalendarFeedCacheKey::forToken('feed-token-abc');
        $cache->put($key, 'STALE', 300);

        $this->actingAs($this->user)
            ->delete("/calendar/{$event->id}")
            ->assertRedirect(route('calendar.index'));

        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
        $this->assertNull($cache->get($key));
    }

    #[Test]
    public function destroy_blocks_another_tenants_event(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => CalendarEvent::factory()->create(),
        );

        $this->actingAs($this->user)
            ->delete("/calendar/{$foreign->id}")
            ->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertDatabaseHas('calendar_events', ['id' => $foreign->id]);
        });
    }

    #[Test]
    public function destroy_requires_authentication(): void
    {
        $event = CalendarEvent::factory()->create();

        $this->delete("/calendar/{$event->id}")->assertRedirect('/login');
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id]);
    }
}
