<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Http\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ShowController
{
    public function __invoke(Contact $contact): Response
    {
        $contact->load(['company' => fn ($q) => $q->select(['id', 'name'])]);

        $outreach = OutreachLogEntry::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get(['id', 'channel', 'subject', 'body', 'occurred_at'])
            ->map(fn (OutreachLogEntry $entry) => [
                'id' => $entry->id,
                'channel' => $entry->channel->value,
                'subject' => $entry->subject,
                'body' => $entry->body,
                'occurred_at' => $entry->occurred_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Contacts/Show', [
            'contact' => (new ContactResource($contact))->resolve(),
            // Outreach log and compose-from-template panels are owned by sibling
            // slices; this controller surfaces the raw log so those slices can
            // hydrate their panels from a shared resource shape.
            'outreach' => $outreach,
        ]);
    }
}
