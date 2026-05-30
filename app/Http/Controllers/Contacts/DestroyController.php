<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Models\Contact;
use Illuminate\Http\RedirectResponse;

final readonly class DestroyController
{
    public function __invoke(Contact $contact): RedirectResponse
    {
        $name = $contact->name;

        // Cascades to outreach_log_entries via the FK cascadeOnDelete.
        $contact->delete();

        return redirect()
            ->route('contacts.index')
            ->with('success', "Removed {$name} from contacts.");
    }
}
