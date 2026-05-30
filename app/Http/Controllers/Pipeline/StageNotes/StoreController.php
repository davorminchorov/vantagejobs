<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline\StageNotes;

use App\Enums\PipelineStatus;
use App\Http\Requests\Pipeline\StageNotes\StoreStageNoteRequest;
use App\Models\Role;
use App\Models\RoleStageNote;
use Illuminate\Http\RedirectResponse;

final readonly class StoreController
{
    public function __invoke(StoreStageNoteRequest $request, Role $role): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $stage = PipelineStatus::from((string) $data['stage']);
        $body = trim((string) $data['body']);

        RoleStageNote::query()->create([
            'role_id' => $role->id,
            'stage' => $stage,
            'body' => $body,
        ]);

        return redirect()
            ->back()
            ->with('success', 'Note added.');
    }
}
