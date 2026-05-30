<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;

final readonly class UpdateController
{
    public function __invoke(UpdateContactRequest $request, Contact $contact): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $contact->fill([
            'name' => $data['name'],
            'role_title' => $data['role_title'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'company_id' => $data['company_id'] ?? null,
        ])->save();

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', "Updated {$contact->name}.");
    }
}
