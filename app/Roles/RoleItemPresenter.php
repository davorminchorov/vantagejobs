<?php

declare(strict_types=1);

namespace App\Roles;

use App\Models\Role;

/**
 * The RoleItem payload shared by the Roles inbox and the company page.
 * Never includes `raw`; `has_description` is derived from it.
 */
final readonly class RoleItemPresenter
{
    public function __construct(private RoleDescriptionExtractor $descriptions) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Role $role): array
    {
        return [
            'id' => (int) $role->id,
            'title' => $role->title,
            'url' => $role->url,
            'location' => $role->location,
            'workplace_type' => $role->workplace_type?->value,
            'department' => $role->department,
            'team' => $role->team,
            'comp_min' => $role->comp_min,
            'comp_max' => $role->comp_max,
            'comp_currency' => $role->comp_currency,
            'comp_interval' => $role->comp_interval,
            'posted_at' => $role->posted_at?->toIso8601String(),
            'created_at' => $role->created_at?->toIso8601String(),
            'source' => $role->source,
            'pipeline_status' => $role->pipeline_status?->value,
            'dismissed_at' => $role->dismissed_at?->toIso8601String(),
            'has_description' => $this->descriptions->hasDescription($role),
        ];
    }
}
