<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Event;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Puts a role into the pipeline at Researching: clears any old applied
 * date, seeds interview prep from the company's latest note when the role
 * has none, and records a ROLE_STATUS_CHANGED event. Shared by the company
 * and roles pages and by Discover's "Add to pipeline".
 */
final readonly class AddRoleToPipeline
{
    public function __construct(
        private InterviewPrefillFromNotes $prefill,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return bool False when the role was already in the pipeline (nothing changed).
     */
    public function add(Role $role, string $source = 'manual'): bool
    {
        if ($role->pipeline_status !== null) {
            return false;
        }

        $role->loadMissing('company');

        $this->db->transaction(function () use ($role, $source): void {
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
                    'source' => $source,
                ]),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });

        return true;
    }
}
