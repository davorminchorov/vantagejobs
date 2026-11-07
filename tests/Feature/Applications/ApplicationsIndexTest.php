<?php

declare(strict_types=1);

namespace Tests\Feature\Applications;

use App\Enums\PipelineStatus;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApplicationsIndexTest extends TestCase
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
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/applications')->assertRedirect('/login');
    }

    #[Test]
    public function page_renders_with_the_full_props_shape(): void
    {
        Template::factory()->create(['name' => 'Thank you']);

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Applications/Index')
                ->where('filters', ['view' => 'all', 'stage' => 'all', 'past' => false, 'role' => null])
                ->has('statuses', 7)
                ->where('statuses.0', ['value' => 'researching', 'label' => 'Researching', 'terminal' => false])
                ->where('statuses.5', ['value' => 'rejected', 'label' => 'Rejected', 'terminal' => true])
                ->where('statuses.6.terminal', true)
                ->where('application_sources', ['cold', 'referral', 'recruiter'])
                ->has('event_types')
                ->has('applications', 0)
                ->where('counts', ['active' => 0, 'attention' => 0, 'upcoming' => 0, 'closed' => 0])
                ->has('agenda', 0)
                ->where('selected', null)
                ->has('templates', 1)
                ->where('templates.0.name', 'Thank you')
                ->has('templates.0.id')
                ->has('templates.0.category')
                ->where('follow_up_threshold_days', 7)
            );
    }

    #[Test]
    public function row_has_the_contract_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Engineer',
            'pipeline_status' => PipelineStatus::OFFER,
            'applied_at' => '2026-05-01 10:00:00',
            'next_action' => 'Reply to offer',
            'next_action_date' => now()->addDays(3)->toDateString(),
        ]);
        Offer::factory()->create(['role_id' => $role->id]);
        $event = CalendarEvent::factory()->forRole($role)->create(['starts_at' => now()->addDays(2), 'timezone' => 'Europe/London']);
        CalendarEvent::factory()->forRole($role)->create(['starts_at' => now()->addDays(9)]);
        CalendarEvent::factory()->forRole($role)->create(['starts_at' => now()->subDays(2)]);

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertInertia(fn ($page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $role->id)
                ->where('applications.0.title', 'Staff Engineer')
                ->where('applications.0.company', ['id' => $company->id, 'name' => 'Acme'])
                ->where('applications.0.status', 'offer')
                ->where('applications.0.next_action', 'Reply to offer')
                ->where('applications.0.next_action_date', now()->addDays(3)->toDateString())
                ->where('applications.0.attention', null)
                ->where('applications.0.has_offer', true)
                ->where('applications.0.next_event.id', $event->id)
                ->where('applications.0.next_event.timezone', 'Europe/London')
                ->has('applications.0.applied_at')
                ->has('applications.0.last_activity_at')
                ->has('applications.0.days_quiet')
            );
    }

    #[Test]
    public function row_without_offer_or_event_has_null_next_event_and_no_offer(): void
    {
        Role::factory()->create(['pipeline_status' => PipelineStatus::RESEARCHING]);

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.has_offer', false)
                ->where('applications.0.next_event', null)
                ->where('applications.0.last_activity_at', null)
                ->where('applications.0.days_quiet', null)
            );
    }

    #[Test]
    public function only_roles_with_a_pipeline_status_are_applications(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $inPipeline = Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => PipelineStatus::APPLIED]);
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => null]);

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertInertia(fn ($page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $inPipeline->id)
                ->where('applications.0.company.name', 'Acme')
            );
    }

    #[Test]
    public function applications_are_sorted_by_newest_activity_with_no_activity_last(): void
    {
        $old = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(20)]);
        $new = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(2)]);
        $none = Role::factory()->create(['pipeline_status' => PipelineStatus::RESEARCHING]);

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.id', $new->id)
                ->where('applications.1.id', $old->id)
                ->where('applications.2.id', $none->id)
            );
    }

    #[Test]
    public function attention_view_returns_only_rows_with_attention(): void
    {
        $quiet = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(30)]);
        $overdue = Role::factory()->create([
            'pipeline_status' => PipelineStatus::ONSITE,
            'applied_at' => now()->subDays(2),
            'next_action_date' => now()->subDay()->toDateString(),
        ]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDay()]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::REJECTED, 'applied_at' => now()->subDays(60)]);

        $this->actingAs($this->user)
            ->get('/applications?view=attention')
            ->assertInertia(fn ($page) => $page
                ->where('filters.view', 'attention')
                ->has('applications', 2)
                ->where('applications.0.id', $overdue->id)
                ->where('applications.0.attention', 'next_step_overdue')
                ->where('applications.1.id', $quiet->id)
                ->where('applications.1.attention', 'quiet')
                ->where('applications.1.days_quiet', 30)
                ->where('counts.attention', 2)
                ->where('counts.active', 3)
                ->where('counts.closed', 1)
            );
    }

    #[Test]
    public function stage_filter_narrows_the_list_but_not_the_counts(): void
    {
        Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()]);
        $screen = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN, 'applied_at' => now()]);

        $this->actingAs($this->user)
            ->get('/applications?stage=screen')
            ->assertInertia(fn ($page) => $page
                ->where('filters.stage', 'screen')
                ->has('applications', 1)
                ->where('applications.0.id', $screen->id)
                ->where('counts.active', 2)
            );
    }

    #[Test]
    public function stage_filter_combines_with_the_attention_view(): void
    {
        $quietApplied = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(30)]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN, 'applied_at' => now()->subDays(30)]);

        $this->actingAs($this->user)
            ->get('/applications?view=attention&stage=applied')
            ->assertInertia(fn ($page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $quietApplied->id)
            );
    }

    #[Test]
    public function upcoming_view_returns_every_application_regardless_of_stage_filter(): void
    {
        Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::REJECTED, 'applied_at' => now()]);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming&stage=applied')
            ->assertInertia(fn ($page) => $page->has('applications', 2));
    }

    #[Test]
    public function unknown_filter_values_fall_back_to_defaults(): void
    {
        $this->actingAs($this->user)
            ->get('/applications?view=bogus&stage=nonsense&past=1&role=abc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters', ['view' => 'all', 'stage' => 'all', 'past' => false, 'role' => null])
            );

        $this->actingAs($this->user)
            ->get('/applications?role=-4')
            ->assertInertia(fn ($page) => $page->where('filters.role', null)->where('selected', null));
    }

    #[Test]
    public function counts_cover_active_attention_upcoming_and_closed(): void
    {
        Role::factory()->create(['pipeline_status' => PipelineStatus::RESEARCHING]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(30)]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::REJECTED]);
        Role::factory()->create(['pipeline_status' => PipelineStatus::WITHDRAWN]);
        CalendarEvent::factory()->count(2)->create(['starts_at' => now()->addDay()]);
        CalendarEvent::factory()->create(['starts_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertInertia(fn ($page) => $page->where('counts', [
                'active' => 2,
                'attention' => 1,
                'upcoming' => 2,
                'closed' => 2,
            ]));
    }

    #[Test]
    public function per_tenant_threshold_setting_changes_attention_and_is_exposed(): void
    {
        Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(10)]);

        $this->actingAs($this->user)->get('/applications')
            ->assertInertia(fn ($page) => $page->where('counts.attention', 1)->where('follow_up_threshold_days', 7));

        app(SettingsRepository::class)->set(SettingKey::FOLLOW_UP_THRESHOLD_DAYS, 14);

        $this->actingAs($this->user)->get('/applications')
            ->assertInertia(fn ($page) => $page->where('counts.attention', 0)->where('follow_up_threshold_days', 14));
    }

    #[Test]
    public function list_is_scoped_to_the_current_tenant(): void
    {
        $own = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($other, function (): void {
            Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]);
            Template::factory()->create();
            CalendarEvent::factory()->create(['starts_at' => now()->addDay()]);
        });

        $this->actingAs($this->user)
            ->get('/applications')
            ->assertInertia(fn ($page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $own->id)
                ->has('templates', 0)
                ->where('counts.upcoming', 0)
            );
    }

    #[Test]
    public function list_query_count_does_not_grow_with_the_number_of_applications(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->get('/applications')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        Role::factory()->count(2)->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(30)]);
        // Warm per-process caches (auth roles) so only list queries are compared.
        $queries();
        $few = $queries();

        Role::factory()->count(10)->create(['pipeline_status' => PipelineStatus::SCREEN, 'applied_at' => now()->subDays(30)]);
        $many = $queries();

        $this->assertSame($few, $many);
    }
}
