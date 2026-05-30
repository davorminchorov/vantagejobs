<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Calendar\DueRemindersQuery;
use App\Models\CalendarEvent;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DueRemindersQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private DueRemindersQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->query = app(DueRemindersQuery::class);
    }

    #[Test]
    public function selects_event_whose_reminder_window_has_opened(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        // Starts in 20 minutes with a 30-minute lead time: window opened
        // 10 minutes ago, so it is due.
        $event = CalendarEvent::factory()->create([
            'starts_at' => $now->addMinutes(20),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        $due = $this->query->get($now);

        $this->assertCount(1, $due);
        $this->assertTrue($due->first()->is($event));
    }

    #[Test]
    public function ignores_event_whose_reminder_window_has_not_opened_yet(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        // Starts in 2 hours with a 30-minute lead time: window opens in
        // 90 minutes, so it is not yet due.
        CalendarEvent::factory()->create([
            'starts_at' => $now->addHours(2),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        $this->assertCount(0, $this->query->get($now));
        $this->assertSame(0, $this->query->count($now));
    }

    #[Test]
    public function ignores_event_with_reminders_disabled(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        CalendarEvent::factory()->withoutReminder()->create([
            'starts_at' => $now->addMinutes(10),
            'reminder_sent_at' => null,
        ]);

        $this->assertCount(0, $this->query->get($now));
    }

    #[Test]
    public function ignores_event_already_reminded(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        CalendarEvent::factory()->create([
            'starts_at' => $now->addMinutes(20),
            'reminder_minutes' => 30,
            'reminder_sent_at' => $now->subMinutes(5),
        ]);

        $this->assertCount(0, $this->query->get($now));
    }

    #[Test]
    public function ignores_event_in_the_past(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        // Already started: never fire a stale reminder even though the
        // lead-time window technically elapsed.
        CalendarEvent::factory()->create([
            'starts_at' => $now->subMinutes(5),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        $this->assertCount(0, $this->query->get($now));
    }

    #[Test]
    public function does_not_cross_the_tenant_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function () use ($now): void {
            CalendarEvent::factory()->create([
                'starts_at' => $now->addMinutes(10),
                'reminder_minutes' => 30,
                'reminder_sent_at' => null,
            ]);
        });

        // Current context is still $this->tenant: the other tenant's due event
        // must not surface.
        $this->assertCount(0, $this->query->get($now));
    }

    #[Test]
    public function orders_soonest_first(): void
    {
        $now = CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC');

        $later = CalendarEvent::factory()->create([
            'starts_at' => $now->addMinutes(25),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);
        $sooner = CalendarEvent::factory()->create([
            'starts_at' => $now->addMinutes(5),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        $due = $this->query->get($now);

        $this->assertTrue($due->first()->is($sooner));
        $this->assertTrue($due->last()->is($later));
    }
}
