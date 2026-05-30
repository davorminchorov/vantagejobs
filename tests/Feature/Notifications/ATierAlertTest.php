<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationKind;
use App\Mail\ATierAlertMail;
use App\Models\Company;
use App\Models\NotificationLogEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ATierAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin to a moment well outside the default 22:00–07:00 DND window in the
        // configured Europe/Berlin timezone (12:00 UTC ≈ 13:00/14:00 CET/CEST).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC'));

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['email' => 'owner@example.test']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function aTierCompany(): Company
    {
        return Company::factory()->create(['tier' => 'A']);
    }

    #[Test]
    public function queues_one_alert_per_a_tier_company_with_a_new_role(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertQueued(
            ATierAlertMail::class,
            fn (ATierAlertMail $mail): bool => $mail->company->is($company)
                && $mail->roles->contains(fn (Role $r): bool => $r->is($role))
                && $mail->hasTo('owner@example.test'),
        );
    }

    #[Test]
    public function groups_multiple_new_roles_into_a_single_email(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        Role::factory()->count(3)->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertQueued(ATierAlertMail::class, 1);
        Mail::assertQueued(
            ATierAlertMail::class,
            fn (ATierAlertMail $mail): bool => $mail->roles->count() === 3,
        );
    }

    #[Test]
    public function records_a_notification_log_entry_for_the_company(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        $this->assertSame(1, NotificationLogEntry::query()
            ->where('kind', NotificationKind::A_TIER_ALERT)
            ->where('company_id', $company->id)
            ->count());
    }

    #[Test]
    public function ignores_companies_below_a_tier(): void
    {
        Mail::fake();

        $company = Company::factory()->create(['tier' => 'B']);
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function ignores_roles_outside_the_lookback_window(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subHours(2),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function ignores_closed_roles(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => false,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function throttles_a_company_already_alerted_within_the_window(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        NotificationLogEntry::factory()
            ->kind(NotificationKind::A_TIER_ALERT)
            ->forCompany($company->id)
            ->sentAt(CarbonImmutable::now()->subMinutes(30))
            ->create();

        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function alerts_again_after_the_throttle_window_elapses(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        NotificationLogEntry::factory()
            ->kind(NotificationKind::A_TIER_ALERT)
            ->forCompany($company->id)
            ->sentAt(CarbonImmutable::now()->subMinutes(90))
            ->create();

        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertQueued(ATierAlertMail::class, 1);
    }

    #[Test]
    public function is_idempotent_across_reruns_within_the_window(): void
    {
        Mail::fake();

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);
        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertQueued(ATierAlertMail::class, 1);
    }

    #[Test]
    public function defers_during_the_do_not_disturb_window_without_recording(): void
    {
        Mail::fake();

        // 00:30 UTC ≈ 02:30 CEST, inside the 22:00–07:00 DND window.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 00:30:00', 'UTC'));

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
        $this->assertSame(0, NotificationLogEntry::query()
            ->where('kind', NotificationKind::A_TIER_ALERT)
            ->count());
    }

    #[Test]
    public function fires_the_deferred_alert_on_the_next_allowed_sweep(): void
    {
        Mail::fake();

        // First sweep falls inside DND and defers.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 00:30:00', 'UTC'));

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);
        Mail::assertNothingQueued();

        // Next allowed sweep after DND ends, still inside the lookback window.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC'));

        Artisan::call('notifications:send-a-tier-alerts', [
            '--tenant' => 'primary',
            '--lookback' => '1000',
        ]);

        Mail::assertQueued(ATierAlertMail::class, 1);
    }

    #[Test]
    public function uses_the_notification_email_setting_when_present(): void
    {
        Mail::fake();

        Setting::factory()->create([
            'key' => 'notification_email',
            'value' => 'alerts@example.test',
        ]);

        $company = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertQueued(
            ATierAlertMail::class,
            fn (ATierAlertMail $mail): bool => $mail->hasTo('alerts@example.test'),
        );
    }

    #[Test]
    public function does_not_alert_across_the_tenant_boundary(): void
    {
        Mail::fake();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test']);
            $company = Company::factory()->create(['tier' => 'A']);
            Role::factory()->create([
                'company_id' => $company->id,
                'is_open' => true,
                'created_at' => CarbonImmutable::now()->subMinutes(5),
            ]);
        });

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function sweeps_every_tenant_when_no_slug_is_given(): void
    {
        Mail::fake();

        $companyPrimary = $this->aTierCompany();
        Role::factory()->create([
            'company_id' => $companyPrimary->id,
            'is_open' => true,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test']);
            $company = Company::factory()->create(['tier' => 'A']);
            Role::factory()->create([
                'company_id' => $company->id,
                'is_open' => true,
                'created_at' => CarbonImmutable::now()->subMinutes(5),
            ]);
        });

        Artisan::call('notifications:send-a-tier-alerts');

        Mail::assertQueued(ATierAlertMail::class, 2);
    }

    #[Test]
    public function fails_for_an_unknown_tenant_slug(): void
    {
        $exitCode = Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'nope']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function does_not_send_or_record_without_a_recipient(): void
    {
        Mail::fake();

        $empty = Tenant::factory()->create(['slug' => 'empty']);
        app(TenantContext::class)->withTenant($empty, function (): void {
            $company = Company::factory()->create(['tier' => 'A']);
            Role::factory()->create([
                'company_id' => $company->id,
                'is_open' => true,
                'created_at' => CarbonImmutable::now()->subMinutes(5),
            ]);
        });

        Artisan::call('notifications:send-a-tier-alerts', ['--tenant' => 'empty']);

        Mail::assertNothingQueued();
        app(TenantContext::class)->withTenant($empty, function (): void {
            $this->assertSame(0, NotificationLogEntry::query()
                ->where('kind', NotificationKind::A_TIER_ALERT)
                ->count());
        });
    }
}
