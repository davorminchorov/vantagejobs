<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Offer;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShowRoleTest extends TestCase
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
    public function unauthenticated_users_are_redirected(): void
    {
        $role = Role::factory()->create();

        $this->get("/pipeline/roles/{$role->id}")->assertRedirect('/login');
    }

    #[Test]
    public function show_renders_role_application_with_company_and_offer_flag(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Engineer',
            'pipeline_status' => PipelineStatus::APPLIED->value,
            'next_action' => 'Email recruiter',
        ]);
        Offer::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->user)
            ->get("/pipeline/roles/{$role->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pipeline/Show')
                ->where('role.title', 'Staff Engineer')
                ->where('role.pipeline_status', 'applied')
                ->where('role.company.name', 'Acme')
                ->where('role.application.next_action', 'Email recruiter')
                ->where('role.has_offer', true)
            );
    }

    #[Test]
    public function show_groups_stage_notes_by_stage_newest_first(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN->value]);

        RoleStageNote::factory()->create([
            'role_id' => $role->id,
            'stage' => PipelineStatus::APPLIED->value,
            'body' => 'submitted via referral',
            'created_at' => now()->subDays(3),
        ]);
        RoleStageNote::factory()->create([
            'role_id' => $role->id,
            'stage' => PipelineStatus::SCREEN->value,
            'body' => 'older screen note',
            'created_at' => now()->subDays(2),
        ]);
        RoleStageNote::factory()->create([
            'role_id' => $role->id,
            'stage' => PipelineStatus::SCREEN->value,
            'body' => 'newer screen note',
            'created_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->get("/pipeline/roles/{$role->id}")
            ->assertInertia(fn ($page) => $page
                ->has('role.stage_notes.applied', 1)
                ->has('role.stage_notes.screen', 2)
                ->where('role.stage_notes.screen.0.body', 'newer screen note')
                ->where('role.stage_notes.screen.1.body', 'older screen note')
            );
    }

    #[Test]
    public function show_includes_tenant_contacts_as_referral_options(): void
    {
        $role = Role::factory()->create();
        Contact::factory()->create(['name' => 'Jordan Referral']);

        $this->actingAs($this->user)
            ->get("/pipeline/roles/{$role->id}")
            ->assertInertia(fn ($page) => $page
                ->has('contacts', 1)
                ->where('contacts.0.name', 'Jordan Referral')
            );
    }

    #[Test]
    public function show_returns_404_for_other_tenants_role(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Role::factory()->create(),
        );

        $this->actingAs($this->user)->get("/pipeline/roles/{$foreign->id}")->assertNotFound();
    }
}
