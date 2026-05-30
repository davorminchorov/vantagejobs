<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationKind;
use App\Mail\WeeklySummaryMail;
use App\Models\Company;
use App\Models\Event;
use App\Models\NotificationLogEntry;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WeeklySummaryTest extends TestCase
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
    public function queues_a_summary_and_records_a_notification_log_row(): void
    {
        Mail::fake();

        $this->seedActivityThisWeek();

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertQueued(
            WeeklySummaryMail::class,
            fn (WeeklySummaryMail $mail): bool => $mail->hasTo('owner@example.test')
                && $mail->summary['new_roles'] >= 1,
        );

        $this->assertSame(
            1,
            NotificationLogEntry::query()
                ->where('kind', NotificationKind::WEEKLY_SUMMARY->value)
                ->count(),
        );
    }

    #[Test]
    public function counts_the_week_correctly(): void
    {
        Mail::fake();

        $this->seedActivityThisWeek();

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertQueued(WeeklySummaryMail::class, function (WeeklySummaryMail $mail): bool {
            return $mail->summary['new_roles'] === 2
                && $mail->summary['companies_added'] === 1
                && $mail->summary['applications_sent'] === 1
                && $mail->summary['offers_recorded'] === 1;
        });
    }

    #[Test]
    public function ranks_top_signals_by_event_type_priority(): void
    {
        Mail::fake();

        $now = CarbonImmutable::now();
        $company = Company::factory()->create();

        Event::factory()->create([
            'company_id' => $company->id,
            'type' => 'blog_post',
            'occurred_at' => $now->subDays(2),
        ]);
        Event::factory()->create([
            'company_id' => $company->id,
            'type' => 'funding',
            'occurred_at' => $now->subDays(1),
        ]);
        Event::factory()->create([
            'company_id' => $company->id,
            'type' => 'layoff',
            'occurred_at' => $now->subDays(3),
        ]);

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertQueued(WeeklySummaryMail::class, function (WeeklySummaryMail $mail): bool {
            $signals = $mail->summary['top_signals'];

            return $signals[0]['type'] === 'funding'
                && $signals[1]['type'] === 'layoff'
                && $signals[2]['type'] === 'blog_post';
        });
    }

    #[Test]
    public function skips_send_when_nothing_happened_and_skip_empty_is_on(): void
    {
        Mail::fake();
        config()->set('notifications.digest_skip_empty', true);

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
        $this->assertSame(0, NotificationLogEntry::query()->count());
    }

    #[Test]
    public function sends_an_empty_summary_when_skip_empty_is_off(): void
    {
        Mail::fake();
        config()->set('notifications.digest_skip_empty', false);

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertQueued(WeeklySummaryMail::class, 1);
    }

    #[Test]
    public function sends_only_once_per_tenant_per_week(): void
    {
        Mail::fake();

        $this->seedActivityThisWeek();

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);
        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertQueued(WeeklySummaryMail::class, 1);
        $this->assertSame(
            1,
            NotificationLogEntry::query()
                ->where('kind', NotificationKind::WEEKLY_SUMMARY->value)
                ->count(),
        );
    }

    #[Test]
    public function does_not_count_activity_outside_the_window(): void
    {
        Mail::fake();
        config()->set('notifications.digest_skip_empty', false);

        $old = CarbonImmutable::now()->subDays(20);
        Role::factory()->create([
            'pipeline_status' => 'researching',
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertQueued(WeeklySummaryMail::class, function (WeeklySummaryMail $mail): bool {
            return $mail->summary['new_roles'] === 0;
        });
    }

    #[Test]
    public function does_not_cross_the_tenant_boundary(): void
    {
        Mail::fake();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test']);
            $now = CarbonImmutable::now();
            Role::factory()->create([
                'pipeline_status' => 'researching',
                'created_at' => $now->subDays(1),
                'updated_at' => $now->subDays(1),
            ]);
        });

        // Sweep only the primary tenant: the other tenant's activity must not
        // leak in and no mail should queue (primary has no activity here, but
        // skip-empty is on by default so nothing sends regardless).
        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'primary']);

        Mail::assertNothingQueued();
        $this->assertSame(0, NotificationLogEntry::query()->count());
    }

    #[Test]
    public function sweeps_every_tenant_when_no_slug_is_given(): void
    {
        Mail::fake();

        $this->seedActivityThisWeek();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            User::factory()->create(['email' => 'other@example.test']);
            $now = CarbonImmutable::now();
            Role::factory()->create([
                'pipeline_status' => 'researching',
                'created_at' => $now->subDays(1),
                'updated_at' => $now->subDays(1),
            ]);
        });

        Artisan::call('notifications:send-weekly-summary');

        Mail::assertQueued(WeeklySummaryMail::class, 2);
    }

    #[Test]
    public function does_not_send_when_the_tenant_has_no_users(): void
    {
        Mail::fake();
        config()->set('notifications.digest_skip_empty', false);

        $empty = Tenant::factory()->create(['slug' => 'empty']);

        Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'empty']);

        Mail::assertNothingQueued();
        app(TenantContext::class)->withTenant($empty, function (): void {
            $this->assertSame(0, NotificationLogEntry::query()->count());
        });
    }

    #[Test]
    public function fails_for_an_unknown_tenant_slug(): void
    {
        $exitCode = Artisan::call('notifications:send-weekly-summary', ['--tenant' => 'nope']);

        $this->assertSame(1, $exitCode);
    }

    /**
     * Seed one of each tracked activity inside the current 7-day window:
     * two roles (one applied), one company, one offer.
     */
    private function seedActivityThisWeek(): void
    {
        $now = CarbonImmutable::now();
        $within = $now->subDays(2);

        $company = Company::factory()->create([
            'created_at' => $within,
            'updated_at' => $within,
        ]);

        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => 'researching',
            'applied_at' => null,
            'created_at' => $within,
            'updated_at' => $within,
        ]);

        $appliedRole = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => 'applied',
            'applied_at' => $within,
            'created_at' => $within,
            'updated_at' => $within,
        ]);

        Offer::factory()->create([
            'role_id' => $appliedRole->id,
            'created_at' => $within,
            'updated_at' => $within,
        ]);
    }
}
