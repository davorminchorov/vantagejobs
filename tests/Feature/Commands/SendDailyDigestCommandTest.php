<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\AggregatorListingStatus;
use App\Enums\NotificationKind;
use App\Mail\DailyDigestMail;
use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Event;
use App\Models\NotificationLogEntry;
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

/**
 * Covers the digest:send-daily command: the local send-hour gate, the
 * one-per-day dedupe, skip-empty, the notification_email Setting override,
 * tenant scoping, and the unknown-slug failure.
 *
 * Tests use --hour to make the local-time gate deterministic, mirroring the
 * production gate against the recipient's wall-clock hour.
 */
final class SendDailyDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create([
            'email' => 'owner@example.test',
            'timezone' => 'UTC',
        ]);
    }

    private function seedActivity(): void
    {
        $company = Company::factory()->create(['tier' => 'A']);
        Event::factory()->create([
            'company_id' => $company->id,
            'occurred_at' => CarbonImmutable::now()->subHour(),
        ]);
    }

    private function currentHour(): int
    {
        return CarbonImmutable::now()->setTimezone('UTC')->hour;
    }

    #[Test]
    public function queues_a_digest_to_the_user_at_the_send_hour(): void
    {
        Mail::fake();
        $this->seedActivity();

        $exit = Artisan::call('digest:send-daily', [
            '--tenant' => 'primary',
            '--hour' => $this->currentHour(),
        ]);

        $this->assertSame(0, $exit);
        Mail::assertQueued(
            DailyDigestMail::class,
            fn (DailyDigestMail $mail): bool => $mail->hasTo('owner@example.test'),
        );
        $this->assertSame(1, NotificationLogEntry::query()
            ->where('kind', NotificationKind::DAILY_DIGEST->value)
            ->count());
    }

    #[Test]
    public function does_not_send_outside_the_configured_hour(): void
    {
        Mail::fake();
        $this->seedActivity();

        $offHour = ($this->currentHour() + 1) % 24;

        Artisan::call('digest:send-daily', [
            '--tenant' => 'primary',
            '--hour' => $offHour,
        ]);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function sends_at_most_one_digest_per_day(): void
    {
        Mail::fake();
        $this->seedActivity();

        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);
        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);

        Mail::assertQueued(DailyDigestMail::class, 1);
        $this->assertSame(1, NotificationLogEntry::query()
            ->where('kind', NotificationKind::DAILY_DIGEST->value)
            ->count());
    }

    #[Test]
    public function skips_an_empty_digest_when_skip_empty_is_on(): void
    {
        Mail::fake();
        // No activity seeded — digest is empty; default config skip-empty is on.

        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);

        Mail::assertNothingQueued();
        $this->assertSame(0, NotificationLogEntry::query()->count());
    }

    #[Test]
    public function sends_an_empty_digest_when_skip_empty_setting_is_off(): void
    {
        Mail::fake();

        Setting::factory()->key('digest_skip_empty', false)->create();

        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);

        Mail::assertQueued(DailyDigestMail::class, 1);
    }

    #[Test]
    public function honors_the_notification_email_setting_override(): void
    {
        Mail::fake();

        AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);
        Setting::factory()->key('notification_email', 'alerts@example.test')->create();

        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);

        Mail::assertQueued(
            DailyDigestMail::class,
            fn (DailyDigestMail $mail): bool => $mail->hasTo('alerts@example.test'),
        );
    }

    #[Test]
    public function does_not_send_when_the_tenant_has_no_recipient(): void
    {
        Mail::fake();

        $empty = Tenant::factory()->create(['slug' => 'empty']);
        app(TenantContext::class)->withTenant($empty, function (): void {
            AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);
        });

        Artisan::call('digest:send-daily', ['--tenant' => 'empty', '--hour' => $this->currentHour()]);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function does_not_cross_the_tenant_boundary(): void
    {
        Mail::fake();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test', 'timezone' => 'UTC']);
            $company = Company::factory()->create(['tier' => 'A']);
            Event::factory()->create([
                'company_id' => $company->id,
                'occurred_at' => CarbonImmutable::now()->subHour(),
            ]);
        });

        // Sweep only primary, which has no activity: nothing queued and the
        // other tenant's recipient is never targeted.
        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function sweeps_every_tenant_when_no_slug_is_given(): void
    {
        Mail::fake();
        $this->seedActivity();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test', 'timezone' => 'UTC']);
            AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);
        });

        Artisan::call('digest:send-daily', ['--hour' => $this->currentHour()]);

        Mail::assertQueued(DailyDigestMail::class, 2);
    }

    #[Test]
    public function fails_for_an_unknown_tenant_slug(): void
    {
        $exit = Artisan::call('digest:send-daily', ['--tenant' => 'nope', '--hour' => $this->currentHour()]);

        $this->assertSame(1, $exit);
    }

    #[Test]
    public function fails_for_an_out_of_range_hour(): void
    {
        $exit = Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => 99]);

        $this->assertSame(1, $exit);
    }

    #[Test]
    public function requires_auth_is_not_applicable_console_command_runs_unauthenticated(): void
    {
        // Console commands run outside the HTTP guard; the tenant boundary is
        // the only access control. This asserts the command is invocable
        // without an authenticated web user and still respects tenant scope.
        Mail::fake();
        $this->seedActivity();

        $this->assertGuest();

        Artisan::call('digest:send-daily', ['--tenant' => 'primary', '--hour' => $this->currentHour()]);

        Mail::assertQueued(DailyDigestMail::class, 1);
    }
}
