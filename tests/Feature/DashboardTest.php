<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AggregatorListingStatus;
use App\Enums\PipelineStatus;
use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function dashboard_shows_seeded_companies_for_current_tenant(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-test']);
        app(TenantContext::class)->setCurrent($tenant);

        $user = User::factory()->create();
        Company::factory()->count(3)->create();

        // Seed an unrelated tenant's company to prove scoping
        $otherTenant = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            Company::factory()->create(['name' => 'Other Tenant Co']);
        });

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('companies_count', 3)
                ->has('companies', 3)
            );
    }

    #[Test]
    public function dashboard_renders_empty_state_when_no_companies(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'empty-dashboard']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies_count', 0)
                ->has('companies', 0)
            );
    }

    #[Test]
    public function watch_list_companies_are_slimmed_to_the_dashboard_shape(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-shape']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        $company = Company::factory()->create(['name' => 'Acme Corp', 'tier' => 'A']);
        Role::factory()->create(['company_id' => $company->id, 'is_open' => true]);
        Role::factory()->create(['company_id' => $company->id, 'is_open' => false]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('companies.0', fn ($row) => $row
                    ->where('id', $company->id)
                    ->where('name', 'Acme Corp')
                    ->where('tier', 'A')
                    ->where('status', $company->status)
                    ->where('open_roles', 1)
                    ->has('last_checked_at')
                    ->etc()
                )
            );
    }

    #[Test]
    public function applications_attention_reports_count_and_top_three(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-attention']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        $company = Company::factory()->create(['name' => 'Overdue Co']);
        $overdue = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Engineer',
            'pipeline_status' => PipelineStatus::ONSITE,
            'next_action_date' => now()->subDays(2)->toDateString(),
        ]);
        $quiet = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Backend Engineer',
            'pipeline_status' => PipelineStatus::APPLIED,
            'applied_at' => now()->subDays(12),
        ]);
        Role::factory()->count(3)->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
            'applied_at' => now()->subDays(40),
        ]);
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications_attention.count', 5)
                ->has('applications_attention.top', 3)
                ->where('applications_attention.top.0.role_id', $overdue->id)
                ->where('applications_attention.top.0.title', 'Staff Engineer')
                ->where('applications_attention.top.0.company_name', 'Overdue Co')
                ->where('applications_attention.top.0.reason', 'next_step_overdue')
                ->where('applications_attention.top.0.reason_label', 'Next step overdue')
                ->where('applications_attention.top.0.url', "/applications?role={$overdue->id}")
                ->where('applications_attention.top.1.reason', 'quiet')
                ->where('applications_attention.top.1.reason_label', 'No activity for 40 days')
            );

        $this->assertNotSame($quiet->id, $overdue->id);
    }

    #[Test]
    public function applications_attention_is_empty_without_applications_needing_it(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-attention-empty']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        Role::factory()->create(['pipeline_status' => PipelineStatus::REJECTED, 'applied_at' => now()->subDays(90)]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('applications_attention.count', 0)
                ->has('applications_attention.top', 0)
            );
    }

    #[Test]
    public function pipeline_summary_counts_active_stages_and_excludes_terminal_ones_from_the_total(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-pipeline']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        $company = Company::factory()->create();
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => PipelineStatus::APPLIED]);
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => PipelineStatus::SCREEN]);
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => PipelineStatus::REJECTED]);
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => null]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Applied + Screen count, Rejected is terminal and excluded.
                ->where('pipeline_summary.total_active', 2)
                ->has('pipeline_summary.stages', 7)
                ->where('pipeline_summary.stages.0.terminal', false)
                ->where('pipeline_summary.stages.5.terminal', true)
                ->where('pipeline_summary.stages.0.label', 'Researching')
            );
    }

    #[Test]
    public function discover_new_count_only_counts_new_listings(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-discover']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        AggregatorListing::factory()->count(2)->create(['status' => AggregatorListingStatus::NEW]);
        AggregatorListing::factory()->create(['status' => AggregatorListingStatus::DISMISSED]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('discover_new_count', 2)
            );
    }

    #[Test]
    public function ats_degraded_company_makes_source_health_status_non_green(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-ats-health']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        Company::factory()->create(['ats_status' => 'redetect_failed', 'ats_redetect_attempts' => 3]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('source_health.status', 'red')
                ->where('source_health.counts.red', 1)
            );
    }
}
