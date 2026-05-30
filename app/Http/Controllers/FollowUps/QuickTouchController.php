<?php

declare(strict_types=1);

namespace App\Http\Controllers\FollowUps;

use App\Enums\OutreachChannel;
use App\Http\Requests\FollowUps\QuickTouchRequest;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Clear a contact's follow-up flag in one click by recording a touch.
 *
 * Resolves the contact by integer id (no route-model binding) so the global
 * BelongsToTenant scope 404s cross-tenant ids. Appends a minimal
 * OutreachLogEntry (append-only; OutreachLogEntry::UPDATED_AT is null) and
 * advances contacts.last_touch in one transaction, mirroring the full
 * outreach-log writer so last_touch never drifts. The note, when present, is
 * stored as the entry body; the channel defaults to OTHER.
 *
 * This controller imports the OutreachLogEntry model but creates none of the
 * outreach-log slice's files.
 */
final readonly class QuickTouchController
{
    public function __invoke(QuickTouchRequest $request, int $contact): RedirectResponse
    {
        /** @var Contact $contactModel */
        $contactModel = Contact::query()->findOrFail($contact);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $channel = isset($data['channel']) && $data['channel'] !== null
            ? OutreachChannel::from((string) $data['channel'])
            : OutreachChannel::OTHER;
        $note = $this->asString($data['note'] ?? null);
        $occurredAt = CarbonImmutable::now();

        DB::transaction(function () use ($contactModel, $channel, $note, $occurredAt): void {
            OutreachLogEntry::query()->create([
                'contact_id' => $contactModel->id,
                'channel' => $channel,
                'subject' => null,
                'body' => $note,
                'occurred_at' => $occurredAt,
            ]);

            // A quick touch is always "now", so it can only ever push
            // last_touch forward — but stay defensive in case of clock skew.
            if ($contactModel->last_touch === null || $occurredAt->greaterThan($contactModel->last_touch)) {
                $contactModel->fill(['last_touch' => $occurredAt])->save();
            }
        });

        return redirect()
            ->route('follow-ups.index')
            ->with('success', 'Logged a touch for '.$contactModel->name.'.');
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
