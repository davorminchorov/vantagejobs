<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use App\Models\Company;
use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexCompaniesController
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->query() === [] && $request->user() !== null) {
            $default = SavedView::query()
                ->where('user_id', $request->user()->id)
                ->where('entity', SavedView::ENTITY_COMPANIES)
                ->where('is_default', true)
                ->first();

            if ($default !== null) {
                $query = http_build_query($default->filters ?? []);
                if ($query !== '') {
                    return redirect()->to('/companies?'.$query.'&_default=1');
                }
            }
        }

        $filters = CompanyListFilters::fromRequest($request);

        $query = Company::query()
            ->withCount(['roles as open_roles_count' => fn ($q) => $q->where('is_open', true)]);

        $filters->applyTo($query);

        $companies = $query
            ->orderBy('tier')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'filters' => $filters->toArray(),
            'options' => [
                'tiers' => CompanyListFilters::VALID_TIERS,
                'statuses' => CompanyListFilters::VALID_STATUSES,
                'ats_types' => array_map(fn (AtsType $t) => $t->value, AtsType::cases()),
                'last_checked' => CompanyListFilters::VALID_LAST_CHECKED,
                // PRD §6.5.2 dimensions whose underlying columns are deferred
                // to Phase 4. Surfaced here so the filter panel can render
                // disabled placeholders without inventing data.
                'stages' => [],
                'sizes' => [],
                'remote_policies' => [],
                'primary_stacks' => [],
            ],
        ]);
    }
}
