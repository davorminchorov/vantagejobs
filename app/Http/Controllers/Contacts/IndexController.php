<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Http\Resources\Contacts\ContactResource;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexController
{
    public function __invoke(Request $request): Response
    {
        $filters = ContactListFilters::fromRequest($request);

        $query = Contact::query()
            // Eager load the company plus its active-pipeline role flag so the
            // resource's needs_followup computation does not lazy-load.
            ->with(['company' => fn ($q) => $q->select(['id', 'name'])]);

        $filters->applyTo($query);

        $contacts = $query
            ->orderByRaw('last_touch is null')
            ->orderBy('last_touch')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => ['id' => $company->id, 'name' => $company->name])
            ->values();

        return Inertia::render('Contacts/Index', [
            'contacts' => ContactResource::collection($contacts),
            'filters' => $filters->toArray(),
            'options' => [
                'companies' => $companies,
                'last_touch' => ContactListFilters::VALID_LAST_TOUCH,
            ],
        ]);
    }
}
