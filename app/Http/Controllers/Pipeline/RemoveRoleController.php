<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class RemoveRoleController
{
    public function __invoke(Role $role): RedirectResponse
    {
        // Not in the pipeline — no-op, avoid writing a phantom status change.
        if ($role->pipeline_status === null) {
            return redirect()
                ->route('companies.show', $role->company_id)
                ->with('info', "{$role->title} is not in your pipeline.");
        }

        DB::transaction(function () use ($role): void {
            $from = $role->pipeline_status;

            // Stage notes and the offer are intentionally preserved — nothing
            // is ever lost. Only the board membership is cleared.
            $role->fill(['pipeline_status' => null])->save();

            Event::query()->create([
                'company_id' => $role->company_id,
                'role_id' => $role->id,
                'type' => EventType::ROLE_STATUS_CHANGED->value,
                'title' => "Removed from pipeline: {$role->title}",
                'body' => (string) json_encode([
                    'from' => $from?->value,
                    'to' => null,
                    'source' => 'manual',
                ]),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });

        return redirect()
            ->route('companies.show', $role->company_id)
            ->with('success', "Removed {$role->title} from your pipeline.");
    }
}
