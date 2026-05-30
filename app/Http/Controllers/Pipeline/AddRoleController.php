<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Http\Requests\Pipeline\AddRoleRequest;
use App\Models\Event;
use App\Models\Role;
use App\Pipeline\InterviewPrefillFromNotes;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class AddRoleController
{
    public function __construct(private InterviewPrefillFromNotes $prefill) {}

    public function __invoke(AddRoleRequest $request, Role $role): RedirectResponse
    {
        // Already opted into the pipeline — nothing to do, keep history clean.
        if ($role->pipeline_status !== null) {
            return redirect()
                ->route('companies.show', $role->company_id)
                ->with('info', "{$role->title} is already in your pipeline.");
        }

        $role->loadMissing('company');

        DB::transaction(function () use ($role): void {
            $from = $role->pipeline_status;

            $attributes = [
                'pipeline_status' => PipelineStatus::RESEARCHING,
                'applied_at' => null,
            ];

            // Seed interview prep from the company dossier (latest Note) only
            // when the role has nothing yet, so a re-add never clobbers edits.
            if (($role->interview_prep === null || trim((string) $role->interview_prep) === '')
                && $role->company !== null) {
                $seed = $this->prefill->for($role->company);
                if ($seed !== null) {
                    $attributes['interview_prep'] = $seed;
                }
            }

            $role->fill($attributes)->save();

            Event::query()->create([
                'company_id' => $role->company_id,
                'role_id' => $role->id,
                'type' => EventType::ROLE_STATUS_CHANGED->value,
                'title' => "Added to pipeline: {$role->title}",
                'body' => (string) json_encode([
                    'from' => $from?->value,
                    'to' => PipelineStatus::RESEARCHING->value,
                    'source' => 'manual',
                ]),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });

        return redirect()
            ->route('companies.show', $role->company_id)
            ->with('success', "Added {$role->title} to your pipeline.");
    }
}
