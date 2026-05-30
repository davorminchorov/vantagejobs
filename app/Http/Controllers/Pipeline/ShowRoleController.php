<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Enums\ApplicationSource;
use App\Enums\PipelineStatus;
use App\Http\Resources\Pipeline\RoleApplicationResource;
use App\Models\Contact;
use App\Models\Role;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ShowRoleController
{
    public function __invoke(Role $role): Response
    {
        $role->load(['company', 'stageNotes', 'offer.revisions']);

        $contacts = Contact::query()
            ->orderBy('name')
            ->get(['id', 'name', 'role_title'])
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'role_title' => $contact->role_title,
            ])
            ->values();

        return Inertia::render('Pipeline/Show', [
            'role' => (new RoleApplicationResource($role))->resolve(),
            'contacts' => $contacts,
            'options' => [
                'application_sources' => array_map(
                    fn (ApplicationSource $s) => $s->value,
                    ApplicationSource::cases(),
                ),
                'pipeline_statuses' => array_map(
                    fn (PipelineStatus $s) => $s->value,
                    PipelineStatus::cases(),
                ),
            ],
        ]);
    }
}
