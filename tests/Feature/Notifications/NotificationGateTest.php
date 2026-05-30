<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationKind;
use App\Models\NotificationLogEntry;
use App\Models\Setting;
use App\Notifications\NotificationGate;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private NotificationGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'gate-co']);
        app(TenantContext::class)->setCurrent($this->tenant);

        config()->set('notifications.dnd_start', '22:00');
        config()->set('notifications.dnd_end', '07:00');
        config()->set('notifications.a_tier_throttle_minutes', 60);

        $this->gate = app(NotificationGate::class);
    }

    #[Test]
    public function moment_inside_wrapping_dnd_window_after_start_is_dnd(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 23, 30, 0, 'Europe/Berlin');

        $this->assertTrue($this->gate->isWithinDnd($moment, 'Europe/Berlin'));
    }

    #[Test]
    public function moment_inside_wrapping_dnd_window_before_end_is_dnd(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 3, 0, 0, 'Europe/Berlin');

        $this->assertTrue($this->gate->isWithinDnd($moment, 'Europe/Berlin'));
    }

    #[Test]
    public function moment_outside_dnd_window_is_allowed(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 12, 0, 0, 'Europe/Berlin');

        $this->assertFalse($this->gate->isWithinDnd($moment, 'Europe/Berlin'));
    }

    #[Test]
    public function dnd_boundary_at_end_is_outside_window(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 7, 0, 0, 'Europe/Berlin');

        $this->assertFalse($this->gate->isWithinDnd($moment, 'Europe/Berlin'));
    }

    #[Test]
    public function dnd_is_evaluated_in_recipient_timezone(): void
    {
        // 05:00 UTC is 07:00 in Europe/Berlin (summer, +02:00) -> outside DND,
        // but in UTC the same instant would still read as 05:00 -> inside DND.
        $moment = CarbonImmutable::create(2026, 5, 30, 5, 0, 0, 'UTC');

        $this->assertFalse($this->gate->isWithinDnd($moment, 'Europe/Berlin'));
        $this->assertTrue($this->gate->isWithinDnd($moment, 'UTC'));
    }

    #[Test]
    public function next_sendable_moment_returns_unchanged_when_outside_dnd(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 12, 0, 0, 'Europe/Berlin');

        $this->assertTrue(
            $this->gate->nextSendableMoment($moment, 'Europe/Berlin')->equalTo($moment)
        );
    }

    #[Test]
    public function next_sendable_moment_defers_late_night_to_morning_boundary(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 23, 30, 0, 'Europe/Berlin');

        $next = $this->gate->nextSendableMoment($moment, 'Europe/Berlin')
            ->setTimezone('Europe/Berlin');

        $this->assertSame('2026-05-31 07:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function next_sendable_moment_defers_early_morning_to_same_day_boundary(): void
    {
        $moment = CarbonImmutable::create(2026, 5, 30, 3, 0, 0, 'Europe/Berlin');

        $next = $this->gate->nextSendableMoment($moment, 'Europe/Berlin')
            ->setTimezone('Europe/Berlin');

        $this->assertSame('2026-05-30 07:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function recently_sent_is_true_within_throttle_window(): void
    {
        NotificationLogEntry::factory()
            ->kind(NotificationKind::A_TIER_ALERT)
            ->forCompany(42)
            ->sentAt(CarbonImmutable::now()->subMinutes(30))
            ->create();

        $this->assertTrue(
            $this->gate->recentlySent(NotificationKind::A_TIER_ALERT, 42)
        );
    }

    #[Test]
    public function recently_sent_is_false_outside_throttle_window(): void
    {
        NotificationLogEntry::factory()
            ->kind(NotificationKind::A_TIER_ALERT)
            ->forCompany(42)
            ->sentAt(CarbonImmutable::now()->subMinutes(90))
            ->create();

        $this->assertFalse(
            $this->gate->recentlySent(NotificationKind::A_TIER_ALERT, 42)
        );
    }

    #[Test]
    public function recently_sent_scopes_to_the_requested_company(): void
    {
        NotificationLogEntry::factory()
            ->kind(NotificationKind::A_TIER_ALERT)
            ->forCompany(7)
            ->sentAt(CarbonImmutable::now()->subMinutes(10))
            ->create();

        $this->assertTrue($this->gate->recentlySent(NotificationKind::A_TIER_ALERT, 7));
        $this->assertFalse($this->gate->recentlySent(NotificationKind::A_TIER_ALERT, 8));
    }

    #[Test]
    public function recently_sent_distinguishes_kinds(): void
    {
        NotificationLogEntry::factory()
            ->kind(NotificationKind::DAILY_DIGEST)
            ->sentAt(CarbonImmutable::now()->subMinutes(5))
            ->create();

        $this->assertTrue($this->gate->recentlySent(NotificationKind::DAILY_DIGEST));
        $this->assertFalse($this->gate->recentlySent(NotificationKind::A_TIER_ALERT));
    }

    #[Test]
    public function recently_sent_honours_explicit_window(): void
    {
        NotificationLogEntry::factory()
            ->kind(NotificationKind::SOURCE_HEALTH)
            ->sentAt(CarbonImmutable::now()->subMinutes(120))
            ->create();

        $this->assertFalse($this->gate->recentlySent(NotificationKind::SOURCE_HEALTH, null, 60));
        $this->assertTrue($this->gate->recentlySent(NotificationKind::SOURCE_HEALTH, null, 180));
    }

    #[Test]
    public function record_sent_appends_a_ledger_row(): void
    {
        $entry = $this->gate->recordSent(
            kind: NotificationKind::A_TIER_ALERT,
            recipient: 'analyst@example.test',
            companyId: 99,
            subject: 'New A-tier role',
            context: ['role_id' => 5],
        );

        $this->assertDatabaseHas('notification_log', [
            'id' => $entry->id,
            'tenant_id' => $this->tenant->id,
            'kind' => NotificationKind::A_TIER_ALERT->value,
            'company_id' => 99,
            'recipient' => 'analyst@example.test',
            'subject' => 'New A-tier role',
        ]);

        $fresh = NotificationLogEntry::query()->findOrFail($entry->id);
        $this->assertSame(['role_id' => 5], $fresh->context);
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->sent_at);
    }

    #[Test]
    public function record_sent_then_recently_sent_round_trips(): void
    {
        $this->gate->recordSent(
            kind: NotificationKind::A_TIER_ALERT,
            recipient: 'analyst@example.test',
            companyId: 12,
        );

        $this->assertTrue($this->gate->recentlySent(NotificationKind::A_TIER_ALERT, 12));
    }

    #[Test]
    public function setting_override_beats_config_for_throttle_minutes(): void
    {
        Setting::query()->create([
            'key' => 'a_tier_throttle_minutes',
            'value' => 15,
        ]);

        $gate = app(NotificationGate::class);

        $this->assertSame(15, $gate->throttleMinutes());
    }

    #[Test]
    public function setting_override_beats_config_for_dnd_window(): void
    {
        Setting::query()->create(['key' => 'dnd_start', 'value' => '20:00']);
        Setting::query()->create(['key' => 'dnd_end', 'value' => '06:00']);

        $gate = app(NotificationGate::class);

        // 20:30 is inside the overridden window but outside the 22:00 default.
        $moment = CarbonImmutable::create(2026, 5, 30, 20, 30, 0, 'Europe/Berlin');

        $this->assertTrue($gate->isWithinDnd($moment, 'Europe/Berlin'));
    }

    #[Test]
    public function recently_sent_respects_tenant_scope(): void
    {
        // A row for another tenant must not satisfy the throttle for this one.
        $otherTenant = Tenant::factory()->create(['slug' => 'other-co']);

        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            NotificationLogEntry::factory()
                ->kind(NotificationKind::A_TIER_ALERT)
                ->forCompany(55)
                ->sentAt(CarbonImmutable::now()->subMinutes(5))
                ->create();
        });

        $this->assertFalse($this->gate->recentlySent(NotificationKind::A_TIER_ALERT, 55));
    }
}
