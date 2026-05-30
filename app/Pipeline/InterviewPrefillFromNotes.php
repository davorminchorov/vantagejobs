<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Models\Company;
use App\Models\Note;

/**
 * Seeds a role's interview-prep markdown from the company's dossier.
 *
 * The project has no dedicated Dossier model; company Notes are the
 * long-form markdown surface attached to a company, so the prefill reads
 * the most recent Note body for the company.
 */
final readonly class InterviewPrefillFromNotes
{
    /**
     * Return the latest Note body for the company, or null when the company
     * has no notes to seed from.
     */
    public function for(Company $company): ?string
    {
        $note = Note::query()
            ->where('company_id', $company->id)
            ->latest('created_at')
            ->first();

        if (! $note instanceof Note) {
            return null;
        }

        $body = trim((string) $note->body);

        return $body === '' ? null : $body;
    }
}
