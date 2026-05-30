<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UpdateStatusTest extends TestCase
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
    public function unauthenticated_users_cannot_change_status(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        $this->post('/pipeline/status', [
            'role_id' => $role->id,
            'pipeline_status' => PipelineStatus::SCREEN->value,
        ])->assertRedirect('/login');

        $role->refresh();
        $this->assertSame(PipelineStatus::APPLIED, $role->pipeline_status);
    }

    #[Test]
    public function moving_a_role_updates_status_and_records_a_status_changed_event(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
            'next_action' => 'Email recruiter',
            'next_action_date' => CarbonImmutable::now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->post('/pipeline/status', [
                'role_id' => $role->id,
                'pipeline_status' => PipelineStatus::SCREEN->value,
            ])
            ->assertRedirect('/pipeline');

        $role->refresh();
        $this->assertSame(PipelineStatus::SCREEN, $role->pipeline_status);
        // Moving stages resets the prior follow-up plan.
        $this->assertNull($role->next_action);
        $this->assertNull($role->next_action_date);

        $event = Event::query()
            ->where('role_id', $role->id)
            ->where('type', EventType::ROLE_STATUS_CHANGED->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($company->id, $event->company_id);
        $body = json_decode((string) $event->body, true);
        $this->assertSame(PipelineStatus::APPLIED->value, $body['from']);
        $this->assertSame(PipelineStatus::SCREEN->value, $body['to']);
    }

    #[Test]
    public function moving_to_the_same_status_is_a_no_op_with_no_event(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        $this->actingAs($this->user)
            ->post('/pipeline/status', [
                'role_id' => $role->id,
                'pipeline_status' => PipelineStatus::APPLIED->value,
            ])
            ->assertRedirect('/pipeline');

        $this->assertSame(
            0,
            Event::query()->where('type', EventType::ROLE_STATUS_CHANGED->value)->count(),
        );
    }

    #[Test]
    public function rejects_an_invalid_pipeline_status(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        $this->actingAs($this->user)
            ->from('/pipeline')
            ->post('/pipeline/status', [
                'role_id' => $role->id,
                'pipeline_status' => 'not_a_status',
            ])
            ->assertRedirect('/pipeline')
            ->assertSessionHasErrors('pipeline_status');

        $role->refresh();
        $this->assertSame(PipelineStatus::APPLIED, $role->pipeline_status);
    }

    #[Test]
    public function requires_a_role_id_and_status(): void
    {
        $this->actingAs($this->user)
            ->from('/pipeline')
            ->post('/pipeline/status', [])
            ->assertRedirect('/pipeline')
            ->assertSessionHasErrors(['role_id', 'pipeline_status']);
    }

    #[Test]
    public function returns_404_for_an_unknown_role(): void
    {
        $this->actingAs($this->user)
            ->post('/pipeline/status', [
                'role_id' => 999999,
                'pipeline_status' => PipelineStatus::SCREEN->value,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function cannot_move_a_role_belonging_to_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        app(TenantContext::class)->setCurrent($otherTenant);
        $otherCompany = Company::factory()->create();
        $otherRole = Role::factory()->create([
            'company_id' => $otherCompany->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        app(TenantContext::class)->setCurrent($this->tenant);

        // Tenant-scoped findOrFail hides the other tenant's row → 404.
        $this->actingAs($this->user)
            ->post('/pipeline/status', [
                'role_id' => $otherRole->id,
                'pipeline_status' => PipelineStatus::SCREEN->value,
            ])
            ->assertNotFound();

        app(TenantContext::class)->setCurrent($otherTenant);
        $otherRole->refresh();
        $this->assertSame(PipelineStatus::APPLIED, $otherRole->pipeline_status);
    }
}
