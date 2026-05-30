<?php

declare(strict_types=1);

namespace App\Http\Resources\Pipeline;

use App\Models\Contact;
use App\Models\Role;
use App\Models\RoleStageNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Role for the application-detail panel: application metadata,
 * the parent company, per-stage notes grouped by stage, and a flag for
 * whether a current offer exists.
 *
 * @mixin Role
 */
final class RoleApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        $company = $role->company;

        /** @var Contact|null $referralContact */
        $referralContact = $role->referral_contact_id !== null
            ? Contact::query()->find($role->referral_contact_id)
            : null;

        return [
            'id' => $role->id,
            'title' => $role->title,
            'url' => $role->url,
            'location' => $role->location,
            'is_open' => (bool) $role->is_open,
            'pipeline_status' => $role->pipeline_status?->value,
            'application' => [
                'applied_at' => $role->applied_at?->toIso8601String(),
                'application_source' => $role->application_source?->value,
                'referral_contact_id' => $role->referral_contact_id,
                'next_action' => $role->next_action,
                'next_action_date' => $role->next_action_date?->toDateString(),
                'general_notes' => $role->general_notes,
            ],
            'interview_prep' => $role->interview_prep,
            'referral_contact' => $referralContact === null ? null : [
                'id' => $referralContact->id,
                'name' => $referralContact->name,
                'role_title' => $referralContact->role_title,
                'email' => $referralContact->email,
                'linkedin_url' => $referralContact->linkedin_url,
            ],
            'company' => $company === null ? null : [
                'id' => $company->id,
                'name' => $company->name,
                'website' => $company->website,
                'careers_url' => $company->careers_url,
                'tier' => $company->tier,
            ],
            'has_offer' => $role->offer !== null,
            'offer' => $role->offer === null
                ? null
                : (new OfferResource($role->offer))->resolve(),
            'stage_notes' => $this->groupStageNotes($role),
        ];
    }

    /**
     * Per-stage notes keyed by PipelineStatus value, newest first within each stage.
     *
     * @return array<string, array<int, array{id: int, body: string, created_at: string|null}>>
     */
    private function groupStageNotes(Role $role): array
    {
        return $role->stageNotes
            ->sortByDesc('created_at')
            ->groupBy(fn (RoleStageNote $note) => $note->stage->value)
            ->map(fn ($notes) => $notes
                ->map(fn (RoleStageNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])
                ->values()
                ->all())
            ->all();
    }
}
