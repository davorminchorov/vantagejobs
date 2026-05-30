<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Enums\PipelineStatus;
use App\Models\Role;
use App\Pipeline\PipelineFollowUp;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexController
{
    public function __construct(private PipelineFollowUp $followUp) {}

    public function __invoke(): Response
    {
        // Default filter: only roles that the user has opted into the pipeline
        // (pipeline_status IS NOT NULL). Scraped roles stay off the board.
        $roles = Role::query()
            ->whereNotNull('pipeline_status')
            ->with('company:id,name')
            ->orderByDesc('applied_at')
            ->orderByDesc('updated_at')
            ->get();

        $flags = $this->followUp->flagsFor($roles);

        $columns = [];
        foreach (PipelineStatus::cases() as $status) {
            $columns[$status->value] = [];
        }

        foreach ($roles as $role) {
            $status = $role->pipeline_status;
            if ($status === null) {
                continue;
            }

            $columns[$status->value][] = [
                'id' => $role->id,
                'title' => $role->title,
                'company_id' => $role->company_id,
                'company_name' => $role->company?->name,
                'pipeline_status' => $status->value,
                'applied_at' => $role->applied_at?->toIso8601String(),
                'next_action' => $role->next_action,
                'next_action_date' => $role->next_action_date?->toDateString(),
                'needs_follow_up' => $flags[$role->id] ?? false,
            ];
        }

        return Inertia::render('Pipeline/Index', [
            'columns' => $columns,
            'statuses' => array_map(
                static fn (PipelineStatus $status): array => [
                    'value' => $status->value,
                    'label' => self::label($status),
                ],
                PipelineStatus::cases(),
            ),
            'follow_up_threshold_days' => $this->followUp->thresholdDays(),
        ]);
    }

    private static function label(PipelineStatus $status): string
    {
        return match ($status) {
            PipelineStatus::RESEARCHING => 'Researching',
            PipelineStatus::APPLIED => 'Applied',
            PipelineStatus::SCREEN => 'Screen',
            PipelineStatus::ONSITE => 'Onsite',
            PipelineStatus::OFFER => 'Offer',
            PipelineStatus::REJECTED => 'Rejected',
            PipelineStatus::WITHDRAWN => 'Withdrawn',
        };
    }
}
