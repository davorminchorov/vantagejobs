<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Models\SavedView;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexCompaniesController
{
    private const int TREND_DAYS = 90;

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
            ->withCount(['roles as open_roles_count' => fn ($q) => $q->where('is_open', true)])
            ->withCount(['roles as new_roles_7d' => fn ($q) => $q
                ->where('is_open', true)
                ->where('created_at', '>=', CarbonImmutable::now()->subDays(7)),
            ])
            ->withMax(['roles as latest_open_posted_at' => fn ($q) => $q->where('is_open', true)], 'posted_at')
            ->addSelect([
                'quality_score' => CompanySnapshot::query()
                    ->select('quality_score')
                    ->whereColumn('company_id', 'companies.id')
                    ->orderByDesc('snapshot_date')
                    ->limit(1),
                'has_recent_layoff_event' => CompanySnapshot::query()
                    ->select('has_recent_layoff_event')
                    ->whereColumn('company_id', 'companies.id')
                    ->orderByDesc('snapshot_date')
                    ->limit(1),
            ]);

        $filters->applyTo($query);
        $filters->applySortTo($query);

        $companies = $query
            ->paginate(20)
            ->withQueryString();

        $trends = $this->hiringTrends($companies->getCollection()->pluck('id')->all());
        foreach ($companies->getCollection() as $company) {
            $company->setAttribute('hiring_trend', $trends[$company->id] ?? array_fill(0, self::TREND_DAYS, null));
        }

        // Phase 4 step 16: stages are derived from observed funding_stage
        // values (free-form per user input) so the filter chip can offer a
        // tenant-scoped multi-select without inventing a whitelist.
        $stages = Company::query()
            ->whereNotNull('funding_stage')
            ->orderBy('funding_stage')
            ->distinct()
            ->pluck('funding_stage')
            ->values()
            ->all();

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'filters' => $filters->toArray(),
            'options' => [
                'tiers' => CompanyListFilters::VALID_TIERS,
                'statuses' => CompanyListFilters::VALID_STATUSES,
                'ats_types' => array_map(fn (AtsType $t) => $t->value, AtsType::cases()),
                'last_checked' => CompanyListFilters::VALID_LAST_CHECKED,
                'sorts' => CompanyListFilters::VALID_SORTS,
                // Phase 4 step 16: stage is now live (sourced from observed
                // funding_stage values). size/remote_policy/primary_stack
                // remain placeholders pending Phase 5+ data work.
                'stages' => $stages,
                'sizes' => [],
                'remote_policies' => [],
                'primary_stacks' => [],
            ],
        ]);
    }

    /**
     * Daily open-role counts for the last 90 days, oldest first, loaded with a
     * single query. Days without a snapshot repeat the previous known count
     * (null before the first snapshot in the window, so history is not invented).
     *
     * @param  list<int>  $companyIds
     * @return array<int, list<int|null>>
     */
    private function hiringTrends(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $today = CarbonImmutable::today();
        $start = $today->subDays(self::TREND_DAYS - 1);

        $byCompany = [];
        CompanySnapshot::query()
            ->whereIn('company_id', $companyIds)
            ->whereBetween('snapshot_date', [$start->toDateString(), $today->toDateString()])
            ->get(['company_id', 'snapshot_date', 'open_role_count'])
            ->each(function (CompanySnapshot $snapshot) use (&$byCompany): void {
                $byCompany[$snapshot->company_id][$snapshot->snapshot_date->toDateString()] = $snapshot->open_role_count;
            });

        $trends = [];
        foreach ($byCompany as $companyId => $counts) {
            $series = [];
            $last = null;
            for ($i = 0; $i < self::TREND_DAYS; $i++) {
                $last = $counts[$start->addDays($i)->toDateString()] ?? $last;
                $series[] = $last;
            }
            $trends[$companyId] = $series;
        }

        return $trends;
    }
}
