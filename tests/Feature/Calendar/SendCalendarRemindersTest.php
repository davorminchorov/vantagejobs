<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Mail\CalendarEventReminderMail;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SendCalendarRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['email' => 'owner@example.test']);
    }

    #[Test]
    public function queues_a_reminder_and_stamps_the_event_sent(): void
    {
        Mail::fake();

        $event = CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now()->addMinutes(20),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);

        Mail::assertQueued(
            CalendarEventReminderMail::class,
            fn (CalendarEventReminderMail $mail): bool => $mail->event->is($event)
                && $mail->hasTo('owner@example.test'),
        );

        $this->assertNotNull($event->fresh()->reminder_sent_at);
    }

    #[Test]
    public function does_not_queue_for_an_event_outside_the_window(): void
    {
        Mail::fake();

        CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now()->addHours(5),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function is_idempotent_across_reruns(): void
    {
        Mail::fake();

        CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now()->addMinutes(20),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);
        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);

        Mail::assertQueued(CalendarEventReminderMail::class, 1);
    }

    #[Test]
    public function never_sends_for_an_event_already_started(): void
    {
        Mail::fake();

        CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now()->subMinutes(5),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function skips_events_with_reminders_disabled(): void
    {
        Mail::fake();

        $event = CalendarEvent::factory()->withoutReminder()->create([
            'starts_at' => CarbonImmutable::now()->addMinutes(10),
            'reminder_sent_at' => null,
        ]);

        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
        $this->assertNull($event->fresh()->reminder_sent_at);
    }

    #[Test]
    public function does_not_remind_across_the_tenant_boundary(): void
    {
        Mail::fake();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            CalendarEvent::factory()->create([
                'starts_at' => CarbonImmutable::now()->addMinutes(20),
                'reminder_minutes' => 30,
                'reminder_sent_at' => null,
            ]);
        });

        // Sweep only the primary tenant: the other tenant's event must be
        // untouched and unsent.
        Artisan::call('calendar:send-reminders', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function sweeps_every_tenant_when_no_slug_is_given(): void
    {
        Mail::fake();

        $eventPrimary = CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now()->addMinutes(20),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);

        $other = Tenant::factory()->create(['slug' => 'other']);
        $otherEventId = app(TenantContext::class)->withTenant($other, function (): int {
            User::factory()->create(['email' => 'other@example.test']);

            return CalendarEvent::factory()->create([
                'starts_at' => CarbonImmutable::now()->addMinutes(20),
                'reminder_minutes' => 30,
                'reminder_sent_at' => null,
            ])->id;
        });

        Artisan::call('calendar:send-reminders');

        Mail::assertQueued(CalendarEventReminderMail::class, 2);
        $this->assertNotNull($eventPrimary->fresh()->reminder_sent_at);

        app(TenantContext::class)->withTenant($other, function () use ($otherEventId): void {
            $this->assertNotNull(CalendarEvent::query()->find($otherEventId)->reminder_sent_at);
        });
    }

    #[Test]
    public function fails_for_an_unknown_tenant_slug(): void
    {
        $exitCode = Artisan::call('calendar:send-reminders', ['--tenant' => 'nope']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function stamps_due_events_sent_even_without_a_recipient(): void
    {
        Mail::fake();

        // Tenant with no users: the due event should still be stamped so it
        // does not re-evaluate forever once a user is added.
        $empty = Tenant::factory()->create(['slug' => 'empty']);
        $eventId = app(TenantContext::class)->withTenant($empty, function (): int {
            return CalendarEvent::factory()->create([
                'starts_at' => CarbonImmutable::now()->addMinutes(20),
                'reminder_minutes' => 30,
                'reminder_sent_at' => null,
            ])->id;
        });

        Artisan::call('calendar:send-reminders', ['--tenant' => 'empty']);

        Mail::assertNothingQueued();
        app(TenantContext::class)->withTenant($empty, function () use ($eventId): void {
            $this->assertNotNull(CalendarEvent::query()->find($eventId)->reminder_sent_at);
        });
    }
}
