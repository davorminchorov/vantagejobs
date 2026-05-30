<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Http\Requests\Pipeline\UpdateStatusRequest;
use App\Models\Event;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class UpdateStatusController
{
    public function __invoke(UpdateStatusRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // No route model binding: resolve through the tenant-scoped query so a
        // role from another tenant 404s rather than leaking across boundaries.
        $role = Role::query()->findOrFail((int) $data['role_id']);

        $to = PipelineStatus::from((string) $data['pipeline_status']);
        $from = $role->pipeline_status;

        if ($from === $to) {
            return redirect()
                ->route('pipeline.index')
                ->with('success', "{$role->title} is already in {$to->value}.");
        }

        DB::transaction(function () use ($role, $from, $to): void {
            $role->fill([
                'pipeline_status' => $to,
                // Moving stage resets the follow-up clock — the prior stage's
                // pending action no longer applies to the new column.
                'next_action' => null,
                'next_action_date' => null,
            ])->save();

            Event::query()->create([
                'company_id' => $role->company_id,
                'role_id' => $role->id,
                'type' => EventType::ROLE_STATUS_CHANGED->value,
                'title' => "Pipeline status: {$from?->value} → {$to->value}",
                'body' => (string) json_encode([
                    'from' => $from?->value,
                    'to' => $to->value,
                ]),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });

        return redirect()
            ->route('pipeline.index')
            ->with('success', "Moved {$role->title} to {$to->value}.");
    }
}
