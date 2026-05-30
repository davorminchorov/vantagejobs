<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts\Outreach;

use App\Enums\OutreachChannel;
use App\Http\Requests\Contacts\Outreach\StoreOutreachRequest;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Append an outreach log entry to a contact and advance its last_touch.
 *
 * Resolves the contact by integer id (no route-model binding) so the global
 * BelongsToTenant scope 404s cross-tenant ids. The log is append-only
 * (OutreachLogEntry::UPDATED_AT is null); creating an entry and bumping
 * contacts.last_touch to the entry's occurred_at happen in one transaction so
 * last_touch never drifts from the latest recorded touch.
 */
final readonly class StoreController
{
    public function __invoke(StoreOutreachRequest $request, int $contact): RedirectResponse
    {
        /** @var Contact $contactModel */
        $contactModel = Contact::query()->findOrFail($contact);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $channel = OutreachChannel::from((string) $data['channel']);
        $subject = $this->asString($data['subject'] ?? null);
        $body = $this->asString($data['body'] ?? null);
        $occurredAt = isset($data['occurred_at']) && $data['occurred_at'] !== null
            ? CarbonImmutable::parse((string) $data['occurred_at'])
            : CarbonImmutable::now();

        DB::transaction(function () use ($contactModel, $channel, $subject, $body, $occurredAt): void {
            OutreachLogEntry::query()->create([
                'contact_id' => $contactModel->id,
                'channel' => $channel,
                'subject' => $subject,
                'body' => $body,
                'occurred_at' => $occurredAt,
            ]);

            // Advance last_touch to this entry's occurred_at. A back-dated entry
            // must not pull last_touch backwards past a more recent touch.
            if ($contactModel->last_touch === null || $occurredAt->greaterThan($contactModel->last_touch)) {
                $contactModel->fill(['last_touch' => $occurredAt])->save();
            }
        });

        return redirect()
            ->route('contacts.show', $contactModel)
            ->with('success', 'Logged outreach to '.$contactModel->name.'.');
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
