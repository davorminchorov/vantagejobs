<?php

declare(strict_types=1);

namespace App\Http\Resources\Contacts;

use App\Enums\PipelineStatus;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wire shape for a single CRM contact.
 *
 * `needs_followup` is computed inline here (rather than reusing the outreach
 * slice's logic) so this slice stays self-contained. A contact needs follow-up
 * when its last touch is older than the configured threshold AND its company
 * has at least one role in an actively-applied pipeline stage.
 *
 * @mixin Contact
 */
final class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role_title' => $this->role_title,
            'linkedin_url' => $this->linkedin_url,
            'email' => $this->email,
            'notes' => $this->notes,
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'last_touch' => $this->last_touch?->toIso8601String(),
            'needs_followup' => $this->needsFollowup(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function needsFollowup(): bool
    {
        if ($this->company_id === null) {
            return false;
        }

        $thresholdDays = (int) config('pipeline.follow_up_threshold_days', 7);
        $cutoff = CarbonImmutable::now()->subDays($thresholdDays);

        $touchedRecently = $this->last_touch !== null
            && $this->last_touch->greaterThan($cutoff);

        if ($touchedRecently) {
            return false;
        }

        $activeStages = [
            PipelineStatus::APPLIED->value,
            PipelineStatus::SCREEN->value,
        ];

        return $this->company !== null
            && $this->company->roles()
                ->whereIn('pipeline_status', $activeStages)
                ->exists();
    }
}
