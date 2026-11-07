<?php

declare(strict_types=1);

namespace Tests\Feature\Applications;

use App\Enums\AttentionReason;
use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Models\User;
use App\Pipeline\ApplicationAttention;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApplicationAttentionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function stale_applied_role_is_quiet(): void
    {
        $role = $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays(30)]);

        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($role));
    }

    #[Test]
    public function screen_stage_can_be_quiet(): void
    {
        $role = $this->role(PipelineStatus::SCREEN, ['applied_at' => now()->subDays(30)]);

        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($role));
    }

    #[Test]
    public function applied_role_with_no_activity_at_all_is_quiet(): void
    {
        $role = $this->role(PipelineStatus::APPLIED, ['applied_at' => null]);

        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($role));
        $this->assertNull($this->assessment($role)['days_quiet']);
        $this->assertNull($this->assessment($role)['last_activity_at']);
    }

    #[Test]
    public function stages_other_than_applied_and_screen_are_never_quiet(): void
    {
        foreach ([PipelineStatus::RESEARCHING, PipelineStatus::ONSITE, PipelineStatus::OFFER] as $status) {
            $role = $this->role($status, ['applied_at' => now()->subDays(90)]);

            $this->assertNull($this->attentionOf($role), $status->value);
        }
    }

    #[Test]
    public function terminal_stages_never_need_attention_even_with_an_overdue_next_step(): void
    {
        foreach ([PipelineStatus::REJECTED, PipelineStatus::WITHDRAWN] as $status) {
            $role = $this->role($status, [
                'applied_at' => now()->subDays(90),
                'next_action_date' => now()->subDays(5)->toDateString(),
            ]);

            $this->assertNull($this->attentionOf($role), $status->value);
        }
    }

    #[Test]
    public function next_step_before_today_is_overdue_in_any_non_terminal_stage(): void
    {
        foreach ([PipelineStatus::RESEARCHING, PipelineStatus::ONSITE, PipelineStatus::OFFER] as $status) {
            $role = $this->role($status, ['next_action_date' => now()->subDay()->toDateString()]);

            $this->assertSame(AttentionReason::NextStepOverdue, $this->attentionOf($role), $status->value);
        }
    }

    #[Test]
    public function next_step_due_today_is_not_overdue(): void
    {
        $role = $this->role(PipelineStatus::ONSITE, ['next_action_date' => now()->toDateString()]);

        $this->assertNull($this->attentionOf($role));
    }

    #[Test]
    public function future_next_step_is_not_overdue(): void
    {
        $role = $this->role(PipelineStatus::ONSITE, ['next_action_date' => now()->addDays(3)->toDateString()]);

        $this->assertNull($this->attentionOf($role));
    }

    #[Test]
    public function overdue_next_step_wins_over_quiet(): void
    {
        $role = $this->role(PipelineStatus::APPLIED, [
            'applied_at' => now()->subDays(60),
            'next_action_date' => now()->subDays(30)->toDateString(),
        ]);

        $this->assertSame(AttentionReason::NextStepOverdue, $this->attentionOf($role));
    }

    #[Test]
    public function threshold_boundary_uses_the_default_of_seven_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));

        $inside = $this->role(PipelineStatus::APPLIED, ['applied_at' => CarbonImmutable::now()->subDays(7)]);
        $outside = $this->role(PipelineStatus::APPLIED, ['applied_at' => CarbonImmutable::now()->subDays(7)->subSecond()]);

        $this->assertNull($this->attentionOf($inside));
        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($outside));
    }

    #[Test]
    public function per_tenant_setting_overrides_the_config_threshold(): void
    {
        $role = $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays(10)]);

        $this->assertSame(7, app(ApplicationAttention::class)->thresholdDays());
        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($role));

        app(SettingsRepository::class)->set(SettingKey::FOLLOW_UP_THRESHOLD_DAYS, 14);

        $this->assertSame(14, app(ApplicationAttention::class)->thresholdDays());
        $this->assertNull($this->attentionOf($role));

        app(SettingsRepository::class)->set(SettingKey::FOLLOW_UP_THRESHOLD_DAYS, 3);
        $recent = $this->role(PipelineStatus::SCREEN, ['applied_at' => now()->subDays(5)]);

        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($recent));
    }

    #[Test]
    public function threshold_setting_is_isolated_per_tenant(): void
    {
        app(SettingsRepository::class)->set(SettingKey::FOLLOW_UP_THRESHOLD_DAYS, 30);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($other, function (): void {
            $this->assertSame(7, app(ApplicationAttention::class)->thresholdDays());
        });

        $this->assertSame(30, app(ApplicationAttention::class)->thresholdDays());
    }

    #[Test]
    public function recent_stage_note_counts_as_activity(): void
    {
        $role = $this->role(PipelineStatus::SCREEN, ['applied_at' => now()->subDays(30)]);
        RoleStageNote::factory()->create([
            'role_id' => $role->id,
            'stage' => PipelineStatus::SCREEN,
            'created_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->assertNull($this->attentionOf($role));
        $this->assertSame(1, $this->assessment($role)['days_quiet']);
    }

    #[Test]
    public function recent_status_change_event_counts_as_activity(): void
    {
        $role = $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays(30)]);
        Event::factory()->create([
            'company_id' => $role->company_id,
            'role_id' => $role->id,
            'type' => EventType::ROLE_STATUS_CHANGED->value,
            'occurred_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->assertNull($this->attentionOf($role));
    }

    #[Test]
    public function other_event_types_do_not_count_as_activity(): void
    {
        $role = $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays(30)]);
        Event::factory()->create([
            'company_id' => $role->company_id,
            'role_id' => $role->id,
            'type' => EventType::NEW_ROLE->value,
            'occurred_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($role));
    }

    #[Test]
    public function a_next_action_date_that_has_passed_counts_as_activity_but_a_future_one_does_not(): void
    {
        $passed = $this->role(PipelineStatus::APPLIED, [
            'applied_at' => now()->subDays(60),
            'next_action_date' => now()->subDay()->toDateString(),
        ]);
        $future = $this->role(PipelineStatus::APPLIED, [
            'applied_at' => now()->subDays(60),
            'next_action_date' => now()->addDays(5)->toDateString(),
        ]);

        // The passed date is itself an overdue next step, but it also resets the quiet clock.
        $this->assertNotNull($this->assessment($passed)['last_activity_at']);
        $this->assertSame(1, $this->assessment($passed)['days_quiet']);
        $this->assertSame(AttentionReason::Quiet, $this->attentionOf($future));
    }

    #[Test]
    public function needing_attention_lists_overdue_before_quiet_and_skips_healthy_rows(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $quiet = $this->role(PipelineStatus::APPLIED, ['company_id' => $company->id, 'title' => 'Quiet one', 'applied_at' => now()->subDays(20)]);
        $overdue = $this->role(PipelineStatus::ONSITE, ['company_id' => $company->id, 'title' => 'Overdue one', 'next_action_date' => now()->subDays(2)->toDateString()]);
        $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDay()]);
        $this->role(PipelineStatus::REJECTED, ['applied_at' => now()->subDays(90)]);
        // Not in the pipeline.
        Role::factory()->create(['pipeline_status' => null]);

        $items = app(ApplicationAttention::class)->needingAttention();

        $this->assertSame([$overdue->id, $quiet->id], $items->pluck('roleId')->all());
        $this->assertSame('Acme', $items->first()->companyName);
        $this->assertSame('Next step overdue', $items->first()->describe());
        $this->assertSame('No activity for 20 days', $items->last()->describe());
        $this->assertSame(route('applications.index', ['role' => $overdue->id]), $items->first()->url());
    }

    #[Test]
    public function needing_attention_is_scoped_to_the_current_tenant(): void
    {
        $own = $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays(20)]);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($other, function (): void {
            $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays(20)]);
        });

        $items = app(ApplicationAttention::class)->needingAttention();

        $this->assertSame([$own->id], $items->pluck('roleId')->all());
    }

    #[Test]
    public function assessment_runs_a_fixed_number_of_queries(): void
    {
        $roles = [];
        foreach (range(1, 12) as $i) {
            $roles[] = $this->role(PipelineStatus::APPLIED, ['applied_at' => now()->subDays($i)]);
        }

        $attention = app(ApplicationAttention::class);
        $attention->assess($roles);

        DB::enableQueryLog();
        $attention->assess($roles);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Setting lookup, latest stage notes, latest status changes.
        $this->assertLessThanOrEqual(3, $count);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function role(PipelineStatus $status, array $attributes = []): Role
    {
        return Role::factory()->create([
            'pipeline_status' => $status,
            'applied_at' => null,
            ...$attributes,
        ]);
    }

    /**
     * @return array{attention: AttentionReason|null, last_activity_at: CarbonImmutable|null, days_quiet: int|null}
     */
    private function assessment(Role $role): array
    {
        return app(ApplicationAttention::class)->assess([$role->fresh()])[$role->id];
    }

    private function attentionOf(Role $role): ?AttentionReason
    {
        return $this->assessment($role)['attention'];
    }
}
