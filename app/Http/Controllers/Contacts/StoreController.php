<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Http\Requests\Contacts\StoreContactRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;

final readonly class StoreController
{
    public function __invoke(StoreContactRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $contact = Contact::query()->create([
            'name' => $data['name'],
            'role_title' => $data['role_title'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'company_id' => $data['company_id'] ?? null,
        ]);

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', "Added {$contact->name} to contacts.");
    }
}
