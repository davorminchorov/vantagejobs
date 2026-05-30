<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Http\Requests\Pipeline\UpdateApplicationRequest;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

final readonly class UpdateApplicationController
{
    /**
     * Edit application metadata, general notes and the interview-prep markdown
     * on a Role. Metadata edits are not eventful — only pipeline status changes
     * write Events — so no Event row is recorded here.
     */
    public function __invoke(UpdateApplicationRequest $request, Role $role): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $appliedAt = isset($data['applied_at']) && $data['applied_at'] !== null
            ? CarbonImmutable::parse((string) $data['applied_at'])
            : null;
        $nextActionDate = isset($data['next_action_date']) && $data['next_action_date'] !== null
            ? CarbonImmutable::parse((string) $data['next_action_date'])
            : null;

        $role->fill([
            'applied_at' => $appliedAt,
            'application_source' => $this->asString($data['application_source'] ?? null),
            'referral_contact_id' => isset($data['referral_contact_id']) && $data['referral_contact_id'] !== null
                ? (int) $data['referral_contact_id']
                : null,
            'next_action' => $this->asString($data['next_action'] ?? null),
            'next_action_date' => $nextActionDate,
            'general_notes' => $this->asString($data['general_notes'] ?? null),
            'interview_prep' => $this->asString($data['interview_prep'] ?? null),
        ])->save();

        return redirect()
            ->route('pipeline.roles.show', $role)
            ->with('success', 'Application details updated.');
    }

    private function asString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
