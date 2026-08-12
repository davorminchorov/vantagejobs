<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Enums\AggregatorSourceType;
use App\Enums\NotificationKind;
use App\Mail\AtsHealthMail;
use App\Mail\SourceHealthMail;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\NotificationLogEntry;
use App\Models\ParserConfidenceDaily;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Sources\SourceHealthQuery;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SourceHealthTest extends TestCase
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
    public function a_source_at_the_failure_threshold_is_red(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'consecutive_failures' => 3,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        $summary = app(SourceHealthQuery::class)->summary();

        $this->assertSame('red', $summary['status']);
        $this->assertSame(1, $summary['counts']['red']);
        $this->assertSame('red', $summary['sources'][0]['status']);
        $this->assertContains('3 consecutive failed runs', $summary['sources'][0]['reasons']);
    }

    #[Test]
    public function a_healthy_recently_run_source_is_green(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create([
            'consecutive_failures' => 0,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        $summary = app(SourceHealthQuery::class)->summary();

        $this->assertSame('green', $summary['status']);
        $this->assertSame(1, $summary['counts']['green']);
        $this->assertContains('healthy', $summary['sources'][0]['reasons']);
    }

    #[Test]
    public function a_stale_source_is_yellow(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create([
            'consecutive_failures' => 0,
            'last_run_at' => CarbonImmutable::now()->subHours(72),
        ]);

        $summary = app(SourceHealthQuery::class)->summary();

        $this->assertSame('yellow', $summary['status']);
        $this->assertSame(1, $summary['counts']['yellow']);
    }

    #[Test]
    public function a_large_week_over_week_confidence_drop_is_red(): void
    {
        $now = CarbonImmutable::now();

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::LARAJOBS)->create([
            'consecutive_failures' => 0,
            'last_run_at' => $now->subHour(),
        ]);

        // Previous week median 0.90, this week 0.50 => ~44% drop (> 20%).
        ParserConfidenceDaily::factory()->forSource($source)->onDay($now->subDays(10))->withP50(0.90)->create();
        ParserConfidenceDaily::factory()->forSource($source)->onDay($now->subDays(2))->withP50(0.50)->create();

        $summary = app(SourceHealthQuery::class)->summary($now);

        $this->assertSame('red', $summary['status']);
        $this->assertNotNull($summary['sources'][0]['confidence_drop_pct']);
        $this->assertGreaterThan(20, $summary['sources'][0]['confidence_drop_pct']);
    }

    #[Test]
    public function disabled_sources_are_excluded(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'enabled' => false,
            'consecutive_failures' => 9,
        ]);

        $summary = app(SourceHealthQuery::class)->summary();

        $this->assertSame(0, $summary['total']);
        $this->assertSame('green', $summary['status']);
    }

    #[Test]
    public function the_query_is_scoped_to_the_active_tenant(): void
    {
        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
                'consecutive_failures' => 5,
                'last_run_at' => CarbonImmutable::now()->subHour(),
            ]);
        });

        // The primary tenant has no sources, so it sees nothing from `other`.
        $summary = app(SourceHealthQuery::class)->summary();

        $this->assertSame(0, $summary['total']);
    }

    #[Test]
    public function the_monitor_command_queues_an_alert_for_a_red_source(): void
    {
        Mail::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'name' => 'Remotive',
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
            'last_error' => 'HTTP 503',
        ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(
            SourceHealthMail::class,
            fn (SourceHealthMail $mail): bool => $mail->sourceName === 'Remotive'
                && $mail->hasTo('owner@example.test'),
        );

        $this->assertDatabaseHas('notification_log', [
            'kind' => NotificationKind::SOURCE_HEALTH->value,
            'company_id' => null,
            'recipient' => 'owner@example.test',
        ]);
    }

    #[Test]
    public function the_monitor_command_does_not_alert_a_green_source(): void
    {
        Mail::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create([
            'consecutive_failures' => 0,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_monitor_command_dedupes_a_sustained_red_source(): void
    {
        Mail::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);
        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(SourceHealthMail::class, 1);
        $this->assertSame(1, NotificationLogEntry::query()
            ->where('kind', NotificationKind::SOURCE_HEALTH)
            ->count());
    }

    #[Test]
    public function the_monitor_command_re_alerts_after_a_recovery(): void
    {
        Mail::fake();

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        // Simulate the source recovering: a recovery row supersedes the alert.
        NotificationLogEntry::factory()
            ->kind(NotificationKind::SOURCE_HEALTH)
            ->create([
                'company_id' => null,
                'context' => ['source_key' => $source->type->value, 'recovered' => true],
                'sent_at' => CarbonImmutable::now()->addMinute(),
            ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(SourceHealthMail::class, 2);
    }

    #[Test]
    public function the_monitor_command_writes_its_own_recovery_row_and_re_alerts(): void
    {
        Mail::fake();

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        // First sweep: red, alerts.
        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);
        Mail::assertQueued(SourceHealthMail::class, 1);

        // The source recovers on its own — no manual NotificationLogEntry.
        $source->fill([
            'consecutive_failures' => 0,
            'last_run_at' => CarbonImmutable::now(),
        ])->save();

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        $this->assertDatabaseHas('notification_log', [
            'kind' => NotificationKind::SOURCE_HEALTH->value,
            'company_id' => null,
            'context->source_key' => $source->type->value,
            'context->recovered' => true,
        ]);
        // Recovery is log-only: still exactly one email sent so far.
        Mail::assertQueued(SourceHealthMail::class, 1);

        // Source degrades again: this time it alerts, because the sweep
        // itself re-armed alreadyAlerted() via the recovery row above.
        $source->fill([
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ])->save();

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(SourceHealthMail::class, 2);
    }

    #[Test]
    public function the_monitor_command_prefers_the_notification_email_setting(): void
    {
        Mail::fake();

        app(SettingsRepository::class)->set(SettingKey::NOTIFICATION_EMAIL, 'alerts@example.test');

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(
            SourceHealthMail::class,
            fn (SourceHealthMail $mail): bool => $mail->hasTo('alerts@example.test'),
        );
    }

    #[Test]
    public function the_monitor_command_does_not_cross_the_tenant_boundary(): void
    {
        Mail::fake();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test']);
            AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
                'consecutive_failures' => 4,
                'last_run_at' => CarbonImmutable::now()->subHour(),
            ]);
        });

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_monitor_command_fails_for_an_unknown_tenant(): void
    {
        $exitCode = Artisan::call('monitor:source-health', ['--tenant' => 'nope']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function the_monitor_command_queues_an_ats_health_alert_for_a_redetect_failed_company(): void
    {
        Mail::fake();

        $company = Company::factory()->create([
            'name' => 'Acme Corp',
            'ats_status' => 'redetect_failed',
            'ats_redetect_attempts' => 3,
        ]);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(
            AtsHealthMail::class,
            fn (AtsHealthMail $mail): bool => $mail->companyId === $company->id
                && $mail->companyName === 'Acme Corp'
                && $mail->hasTo('owner@example.test'),
        );

        $this->assertDatabaseHas('notification_log', [
            'kind' => NotificationKind::ATS_HEALTH->value,
            'company_id' => $company->id,
            'recipient' => 'owner@example.test',
        ]);
    }

    #[Test]
    public function the_monitor_command_dedupes_a_sustained_ats_health_alert(): void
    {
        Mail::fake();

        Company::factory()->create(['ats_status' => 'redetect_failed']);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);
        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(AtsHealthMail::class, 1);
        $this->assertSame(1, NotificationLogEntry::query()
            ->where('kind', NotificationKind::ATS_HEALTH)
            ->count());
    }

    #[Test]
    public function the_monitor_command_re_alerts_ats_health_after_a_recovery(): void
    {
        Mail::fake();

        $company = Company::factory()->create(['ats_status' => 'redetect_failed']);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);
        Mail::assertQueued(AtsHealthMail::class, 1);

        // The company recovers on its own.
        $company->fill(['ats_status' => 'ok'])->save();

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        $this->assertDatabaseHas('notification_log', [
            'kind' => NotificationKind::ATS_HEALTH->value,
            'company_id' => $company->id,
            'context->recovered' => true,
        ]);
        Mail::assertQueued(AtsHealthMail::class, 1);

        // It degrades again: this time it alerts, because the recovery row
        // re-armed alreadyAlertedForCompany().
        $company->fill(['ats_status' => 'redetect_failed'])->save();

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertQueued(AtsHealthMail::class, 2);
    }

    #[Test]
    public function the_monitor_command_does_not_alert_ats_health_for_a_healthy_company(): void
    {
        Mail::fake();

        Company::factory()->create(['ats_status' => 'ok']);

        Artisan::call('monitor:source-health', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_dashboard_exposes_the_source_health_summary(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'consecutive_failures' => 4,
            'last_run_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('source_health.status', 'red')
                ->where('source_health.counts.red', 1)
                ->has('source_health.sources', 1)
            );
    }
}
