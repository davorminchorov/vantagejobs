<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Event;
use App\Models\Note;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AddRoleToPipelineTest extends TestCase
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
    public function adds_a_role_to_the_pipeline_and_records_a_status_change_event(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
        ]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/add")
            ->assertRedirect("/companies/{$company->id}");

        $role->refresh();
        $this->assertSame(PipelineStatus::RESEARCHING, $role->pipeline_status);
        $this->assertNull($role->applied_at);

        $event = Event::query()
            ->where('role_id', $role->id)
            ->where('type', EventType::ROLE_STATUS_CHANGED->value)
            ->first();
        $this->assertNotNull($event);
        $body = json_decode((string) $event->body, true);
        $this->assertNull($body['from']);
        $this->assertSame(PipelineStatus::RESEARCHING->value, $body['to']);
    }

    #[Test]
    public function seeds_interview_prep_from_the_companys_latest_note(): void
    {
        $company = Company::factory()->create();
        Note::factory()->create([
            'company_id' => $company->id,
            'body' => 'Stale dossier note.',
            'created_at' => now()->subDays(5),
        ]);
        Note::factory()->create([
            'company_id' => $company->id,
            'body' => 'Latest dossier note with prep material.',
            'created_at' => now(),
        ]);

        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
            'interview_prep' => null,
        ]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/add");

        $role->refresh();
        $this->assertSame('Latest dossier note with prep material.', $role->interview_prep);
    }

    #[Test]
    public function leaves_interview_prep_null_when_company_has_no_notes(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
            'interview_prep' => null,
        ]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/add");

        $role->refresh();
        $this->assertNull($role->interview_prep);
    }

    #[Test]
    public function does_not_overwrite_existing_interview_prep(): void
    {
        $company = Company::factory()->create();
        Note::factory()->create([
            'company_id' => $company->id,
            'body' => 'Dossier note that should not be used.',
        ]);
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
            'interview_prep' => 'My own prep notes.',
        ]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/add");

        $role->refresh();
        $this->assertSame('My own prep notes.', $role->interview_prep);
    }

    #[Test]
    public function adding_a_role_already_in_the_pipeline_is_a_no_op(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED,
        ]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/add")
            ->assertRedirect("/companies/{$company->id}");

        $role->refresh();
        $this->assertSame(PipelineStatus::APPLIED, $role->pipeline_status);
        $this->assertSame(
            0,
            Event::query()->where('role_id', $role->id)->where('type', EventType::ROLE_STATUS_CHANGED->value)->count()
        );
    }

    #[Test]
    public function guests_cannot_add_a_role_to_the_pipeline(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => null,
        ]);

        $this->post("/pipeline/roles/{$role->id}/add")
            ->assertRedirect('/login');

        $role->refresh();
        $this->assertNull($role->pipeline_status);
    }

    #[Test]
    public function a_role_from_another_tenant_cannot_be_added(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherCompany = Company::factory()->create();
        $otherRole = Role::factory()->create([
            'company_id' => $otherCompany->id,
            'pipeline_status' => null,
        ]);

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$otherRole->id}/add")
            ->assertNotFound();

        $otherRole->refresh();
        $this->assertNull($otherRole->pipeline_status);
    }
}
