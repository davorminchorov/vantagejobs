<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline\StageNotes;

use App\Enums\PipelineStatus;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stage notes are an append-only log: changing the role's pipeline status,
 * or clearing it entirely, must never touch existing notes.
 */
final class StageNotesPersistTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function changing_role_status_leaves_notes_intact(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN]);

        $screenNote = RoleStageNote::factory()
            ->forStage(PipelineStatus::SCREEN)
            ->create(['role_id' => $role->id, 'body' => 'Phone screen notes.']);
        $appliedNote = RoleStageNote::factory()
            ->forStage(PipelineStatus::APPLIED)
            ->create(['role_id' => $role->id, 'body' => 'Application notes.']);

        $role->update(['pipeline_status' => PipelineStatus::ONSITE]);

        $this->assertDatabaseHas('role_stage_notes', [
            'id' => $screenNote->id,
            'body' => 'Phone screen notes.',
            'stage' => PipelineStatus::SCREEN->value,
        ]);
        $this->assertDatabaseHas('role_stage_notes', [
            'id' => $appliedNote->id,
            'body' => 'Application notes.',
            'stage' => PipelineStatus::APPLIED->value,
        ]);
        $this->assertSame(2, RoleStageNote::query()->where('role_id', $role->id)->count());
    }

    #[Test]
    public function clearing_pipeline_status_leaves_notes_intact(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER]);

        $note = RoleStageNote::factory()
            ->forStage(PipelineStatus::OFFER)
            ->create(['role_id' => $role->id, 'body' => 'Offer discussion.']);

        $role->update(['pipeline_status' => null]);

        $role->refresh();
        $this->assertNull($role->pipeline_status);
        $this->assertDatabaseHas('role_stage_notes', [
            'id' => $note->id,
            'body' => 'Offer discussion.',
            'stage' => PipelineStatus::OFFER->value,
        ]);
    }

    #[Test]
    public function notes_are_returned_grouped_by_stage_in_chronological_order(): void
    {
        $role = Role::factory()->create();

        $first = RoleStageNote::factory()
            ->forStage(PipelineStatus::SCREEN)
            ->create(['role_id' => $role->id, 'created_at' => now()->subDays(3)]);
        $second = RoleStageNote::factory()
            ->forStage(PipelineStatus::SCREEN)
            ->create(['role_id' => $role->id, 'created_at' => now()->subDay()]);
        RoleStageNote::factory()
            ->forStage(PipelineStatus::APPLIED)
            ->create(['role_id' => $role->id, 'created_at' => now()->subDays(5)]);

        $screenNotes = RoleStageNote::query()
            ->where('role_id', $role->id)
            ->where('stage', PipelineStatus::SCREEN->value)
            ->orderBy('created_at')
            ->get();

        $this->assertSame(
            [$first->id, $second->id],
            $screenNotes->pluck('id')->all(),
        );
    }

    #[Test]
    public function the_model_has_no_updated_at_timestamp(): void
    {
        $this->assertNull(RoleStageNote::UPDATED_AT);
    }
}
