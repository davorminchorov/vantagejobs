<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Event;
use App\Models\Offer;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RemoveRoleFromPipelineTest extends TestCase
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
    public function removes_a_role_from_the_pipeline_and_records_a_status_change_event(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::SCREEN,
        ]);

        $this->actingAs($this->user)
            ->delete("/pipeline/roles/{$role->id}/remove")
            ->assertRedirect("/companies/{$company->id}");

        $role->refresh();
        $this->assertNull($role->pipeline_status);

        $event = Event::query()
            ->where('role_id', $role->id)
            ->where('type', EventType::ROLE_STATUS_CHANGED->value)
            ->first();
        $this->assertNotNull($event);
        $body = json_decode((string) $event->body, true);
        $this->assertSame(PipelineStatus::SCREEN->value, $body['from']);
        $this->assertNull($body['to']);
    }

    #[Test]
    public function removal_preserves_stage_notes_and_the_offer(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::OFFER,
        ]);
        RoleStageNote::factory()->count(2)->create(['role_id' => $role->id]);
        Offer::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->user)
            ->delete("/pipeline/roles/{$role->id}/remove")
            ->assertRedirect("/companies/{$company->id}");

        $this->assertSame(2, RoleStageNote::query()->where('role_id', $role->id)->count());
        $this->assertSame(1, Offer::query()->where('role_id', $role->id)->count());
    }

    #[Test]
    public function removing_a_role_not_in_the_pipeline_is_a_no_op(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
        ]);

        $this->actingAs($this->user)
            ->delete("/pipeline/roles/{$role->id}/remove")
            ->assertRedirect("/companies/{$company->id}");

        $this->assertSame(
            0,
            Event::query()->where('role_id', $role->id)->where('type', EventType::ROLE_STATUS_CHANGED->value)->count()
        );
    }

    #[Test]
    public function guests_cannot_remove_a_role_from_the_pipeline(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        $this->delete("/pipeline/roles/{$role->id}/remove")
            ->assertRedirect('/login');

        $role->refresh();
        $this->assertSame(PipelineStatus::APPLIED, $role->pipeline_status);
    }

    #[Test]
    public function a_role_from_another_tenant_cannot_be_removed(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherCompany = Company::factory()->create();
        $otherRole = Role::factory()->create([
            'company_id' => $otherCompany->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->delete("/pipeline/roles/{$otherRole->id}/remove")
            ->assertNotFound();

        $otherRole->refresh();
        $this->assertSame(PipelineStatus::APPLIED, $otherRole->pipeline_status);
    }
}
