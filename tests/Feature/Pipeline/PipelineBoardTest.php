<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PipelineBoardTest extends TestCase
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
        $this->get('/pipeline')->assertRedirect('/login');
    }

    #[Test]
    public function board_renders_seven_columns_keyed_by_status(): void
    {
        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pipeline/Index')
                ->has('columns.researching')
                ->has('columns.applied')
                ->has('columns.screen')
                ->has('columns.onsite')
                ->has('columns.offer')
                ->has('columns.rejected')
                ->has('columns.withdrawn')
                ->has('statuses', 7)
                ->where('follow_up_threshold_days', 7)
            );
    }

    #[Test]
    public function only_roles_with_a_pipeline_status_appear_on_the_board(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);

        $inPipeline = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        // No pipeline_status — a scraped role the user has not opted in.
        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pipeline/Index')
                ->has('columns.applied', 1)
                ->where('columns.applied.0.id', $inPipeline->id)
                ->where('columns.applied.0.company_name', 'Acme')
                ->has('columns.researching', 0)
            );
    }

    #[Test]
    public function stale_applied_role_is_flagged_for_follow_up(): void
    {
        $company = Company::factory()->create();

        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
            'applied_at' => CarbonImmutable::now()->subDays(30),
            'next_action_date' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('columns.applied.0.needs_follow_up', true)
            );
    }

    #[Test]
    public function recent_activity_clears_the_follow_up_flag(): void
    {
        $company = Company::factory()->create();

        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::SCREEN,
            'applied_at' => CarbonImmutable::now()->subDays(30),
        ]);

        // A fresh stage note counts as activity inside the threshold window.
        RoleStageNote::factory()->create([
            'role_id' => $role->id,
            'stage' => PipelineStatus::SCREEN,
            'created_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('columns.screen.0.needs_follow_up', false)
            );
    }

    #[Test]
    public function terminal_stages_are_never_flagged_for_follow_up(): void
    {
        $company = Company::factory()->create();

        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::OFFER,
            'applied_at' => CarbonImmutable::now()->subDays(90),
        ]);

        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('columns.offer.0.needs_follow_up', false)
            );
    }

    #[Test]
    public function recent_status_change_event_clears_the_follow_up_flag(): void
    {
        $company = Company::factory()->create();

        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
            'applied_at' => CarbonImmutable::now()->subDays(30),
        ]);

        Event::factory()->create([
            'company_id' => $company->id,
            'role_id' => $role->id,
            'type' => EventType::ROLE_STATUS_CHANGED->value,
            'occurred_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('columns.applied.0.needs_follow_up', false)
            );
    }

    #[Test]
    public function board_is_scoped_to_the_current_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        $ownCompany = Company::factory()->create();
        $ownRole = Role::factory()->create([
            'company_id' => $ownCompany->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        // A role belonging to a different tenant must not leak onto the board.
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherCompany = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $otherCompany->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->get('/pipeline')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('columns.applied', 1)
                ->where('columns.applied.0.id', $ownRole->id)
            );
    }
}
