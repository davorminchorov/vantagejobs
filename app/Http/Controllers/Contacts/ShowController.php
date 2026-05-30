<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Enums\OutreachChannel;
use App\Http\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use App\Models\Template;
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

        $templates = Template::query()
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->map(fn (Template $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category->value,
            ])
            ->values();

        return Inertia::render('Contacts/Show', [
            'contact' => (new ContactResource($contact))->resolve(),
            'outreach' => $outreach,
            'templates' => $templates,
            'channels' => array_map(
                fn (OutreachChannel $channel) => $channel->value,
                OutreachChannel::cases(),
            ),
        ]);
    }
}
