<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline\StageNotes;

use App\Enums\PipelineStatus;
use App\Http\Controllers\Pipeline\StageNotes\StoreController;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoreStageNoteTest extends TestCase
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

        // Routes will be added by the wiring patch — register inline for now
        // so the controller can be exercised end-to-end.
        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::post('/pipeline/roles/{role}/stage-notes', StoreController::class)
                ->name('pipeline.roles.stage-notes.store');
        });
    }

    #[Test]
    public function it_appends_a_stage_note_for_the_role(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/stage-notes", [
                'stage' => PipelineStatus::SCREEN->value,
                'body' => '  Recruiter call went well.  ',
            ])
            ->assertRedirect();

        $note = RoleStageNote::query()->where('role_id', $role->id)->first();
        $this->assertNotNull($note);
        $this->assertSame(PipelineStatus::SCREEN, $note->stage);
        $this->assertSame('Recruiter call went well.', $note->body);
        $this->assertSame($this->tenant->id, $note->tenant_id);
    }

    #[Test]
    public function it_does_not_set_an_updated_at_column(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/stage-notes", [
                'stage' => PipelineStatus::APPLIED->value,
                'body' => 'Submitted application.',
            ]);

        $note = RoleStageNote::query()->where('role_id', $role->id)->firstOrFail();
        $this->assertNull($note->getAttribute('updated_at'));
        $this->assertNotNull($note->created_at);
    }

    #[Test]
    public function stage_must_be_a_valid_pipeline_status(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/stage-notes", [
                'stage' => 'not-a-real-stage',
                'body' => 'Body text.',
            ])
            ->assertSessionHasErrors('stage');

        $this->assertSame(0, RoleStageNote::query()->count());
    }

    #[Test]
    public function body_is_required(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/stage-notes", [
                'stage' => PipelineStatus::RESEARCHING->value,
                'body' => '',
            ])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, RoleStageNote::query()->count());
    }

    #[Test]
    public function guests_cannot_add_stage_notes(): void
    {
        $role = Role::factory()->create();

        $this->post("/pipeline/roles/{$role->id}/stage-notes", [
            'stage' => PipelineStatus::APPLIED->value,
            'body' => 'Body text.',
        ])->assertRedirect('/login');

        $this->assertSame(0, RoleStageNote::query()->count());
    }

    #[Test]
    public function notes_are_scoped_to_the_current_tenant(): void
    {
        $role = Role::factory()->create();

        // A role in another tenant must not be writable through this tenant's
        // context — the global scope hides it, so binding 404s.
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherRole = Role::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$otherRole->id}/stage-notes", [
                'stage' => PipelineStatus::APPLIED->value,
                'body' => 'Cross-tenant attempt.',
            ])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/stage-notes", [
                'stage' => PipelineStatus::APPLIED->value,
                'body' => 'Same tenant note.',
            ])
            ->assertRedirect();

        $note = RoleStageNote::query()->firstOrFail();
        $this->assertSame($this->tenant->id, $note->tenant_id);
        $this->assertSame($role->id, $note->role_id);
    }
}
