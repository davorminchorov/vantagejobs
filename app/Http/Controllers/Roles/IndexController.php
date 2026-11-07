<?php

declare(strict_types=1);

namespace App\Http\Controllers\Roles;

use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Models\Role;
use App\Roles\RoleItemPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles inbox at /roles: open roles grouped by company, newest company
 * activity first. Dismissed roles live behind the `status=dismissed` tab.
 */
final readonly class IndexController
{
    private const int PER_PAGE = 12;

    private const int ROLES_PER_GROUP = 10;

    private const int ROLES_PER_FOCUSED_GROUP = 200;

    private const array TIERS = ['A', 'B', 'C'];

    private const array POSTED = ['7d' => 7, '30d' => 30];

    public function __construct(private RoleItemPresenter $presenter) {}

    public function __invoke(Request $request): Response
    {
        $filters = $this->normalizeFilters($request);

        $groups = $this->matching($filters, $filters['status'])
            ->selectRaw('roles.company_id')
            ->selectRaw('COUNT(*) AS total_matching')
            ->selectRaw('COUNT(*) FILTER (WHERE roles.created_at >= ?) AS new_this_week', [CarbonImmutable::now()->subDays(7)])
            ->selectRaw('MAX(COALESCE(roles.posted_at, roles.created_at)) AS latest_activity')
            ->groupBy('roles.company_id')
            ->orderByRaw('latest_activity DESC')
            ->orderBy('roles.company_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $this->hydrate($groups, $filters);

        return Inertia::render('Roles/Index', [
            'groups' => Inertia::scroll($groups)->matchOn('data.id'),
            'filters' => $filters,
            'counts' => $this->counts($filters),
            'options' => [
                'tiers' => self::TIERS,
                'workplaces' => [
                    ['value' => WorkplaceType::REMOTE->value, 'label' => 'Remote'],
                    ['value' => WorkplaceType::HYBRID->value, 'label' => 'Hybrid'],
                    ['value' => WorkplaceType::ON_SITE->value, 'label' => 'On-site'],
                ],
                'posted' => [
                    ['value' => '7d', 'label' => 'Last 7 days'],
                    ['value' => '30d', 'label' => 'Last 30 days'],
                ],
            ],
        ]);
    }

    /**
     * Replaces the paginator's aggregate rows with the final group payloads.
     * Costs four queries per page regardless of group count: company rows,
     * latest quality scores, light role id list, full rows for shown roles.
     *
     * @param  LengthAwarePaginator<int, mixed>  $groups
     * @param  array{status: string, q: ?string, tier: ?string, workplace: ?string, posted: ?string, company: ?int}  $filters
     */
    private function hydrate(LengthAwarePaginator $groups, array $filters): void
    {
        $aggregates = $groups->getCollection();
        $companyIds = $aggregates->pluck('company_id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($companyIds === []) {
            return;
        }

        $companies = Company::query()
            ->whereIn('id', $companyIds)
            ->get(['id', 'name', 'tier', 'domain', 'funding_last_raised_at'])
            ->keyBy('id');

        $scores = CompanySnapshot::query()
            ->selectRaw('DISTINCT ON (company_id) company_id, quality_score')
            ->whereIn('company_id', $companyIds)
            ->orderBy('company_id')
            ->orderByDesc('snapshot_date')
            ->get()
            ->mapWithKeys(static fn (CompanySnapshot $s): array => [
                (int) $s->company_id => $s->quality_score === null ? null : (int) $s->quality_score,
            ]);

        $idRows = $this->matching($filters, $filters['status'])
            ->whereIn('roles.company_id', $companyIds)
            ->orderByRaw('COALESCE(roles.posted_at, roles.created_at) DESC')
            ->orderByDesc('roles.id')
            ->get(['roles.id', 'roles.company_id']);

        /** @var array<int, list<int>> $idsByCompany */
        $idsByCompany = [];
        foreach ($idRows as $row) {
            $idsByCompany[(int) $row->company_id][] = (int) $row->id;
        }

        $shownLimit = $filters['company'] !== null ? self::ROLES_PER_FOCUSED_GROUP : self::ROLES_PER_GROUP;

        $shownIds = [];
        foreach ($idsByCompany as $ids) {
            array_push($shownIds, ...array_slice($ids, 0, $shownLimit));
        }
        $roles = Role::query()->whereIn('id', $shownIds)->get()->keyBy('id');

        $fundedCutoff = CarbonImmutable::now()->subMonths(18);

        $groups->setCollection($aggregates->map(function (object $aggregate) use ($companies, $scores, $idsByCompany, $roles, $fundedCutoff, $shownLimit): array {
            $companyId = (int) $aggregate->company_id;
            $company = $companies->get($companyId);
            $ids = $idsByCompany[$companyId] ?? [];

            return [
                'id' => $companyId,
                'company' => [
                    'id' => $companyId,
                    'name' => (string) $company?->name,
                    'tier' => (string) $company?->tier,
                    'domain' => $company?->domain,
                    'quality_score' => $scores->get($companyId),
                    'funded_recently' => $company?->funding_last_raised_at !== null
                        && $company->funding_last_raised_at->greaterThanOrEqualTo($fundedCutoff),
                ],
                'total_matching' => (int) $aggregate->total_matching,
                'new_this_week' => (int) $aggregate->new_this_week,
                'role_ids' => $ids,
                'roles' => array_values(array_filter(array_map(
                    fn (int $id): ?array => isset($roles[$id]) ? $this->presenter->toArray($roles[$id]) : null,
                    array_slice($ids, 0, $shownLimit),
                ))),
            ];
        }));
    }

    /**
     * Role counts per status tab, scoped by every non-status filter.
     *
     * @param  array{status: string, q: ?string, tier: ?string, workplace: ?string, posted: ?string, company: ?int}  $filters
     * @return array{open: int, dismissed: int}
     */
    private function counts(array $filters): array
    {
        $row = $this->matching($filters, null)
            ->selectRaw('COUNT(*) FILTER (WHERE roles.dismissed_at IS NULL) AS open_count')
            ->selectRaw('COUNT(*) FILTER (WHERE roles.dismissed_at IS NOT NULL) AS dismissed_count')
            ->first();

        return [
            'open' => (int) ($row?->open_count ?? 0),
            'dismissed' => (int) ($row?->dismissed_count ?? 0),
        ];
    }

    /**
     * Open roles at non-graveyard companies, narrowed by the filters.
     * A null $status skips the dismissed_at constraint (used for counts).
     *
     * @param  array{status: string, q: ?string, tier: ?string, workplace: ?string, posted: ?string, company: ?int}  $filters
     * @return Builder<Role>
     */
    private function matching(array $filters, ?string $status): Builder
    {
        $query = Role::query()
            ->join('companies', 'companies.id', '=', 'roles.company_id')
            ->where('roles.is_open', true)
            ->where('companies.tier', '<>', 'graveyard')
            ->where('companies.status', '<>', 'graveyard');

        if ($status === 'open') {
            $query->whereNull('roles.dismissed_at');
        } elseif ($status === 'dismissed') {
            $query->whereNotNull('roles.dismissed_at');
        }

        if ($filters['q'] !== null) {
            $needle = '%'.addcslashes($filters['q'], '\\%_').'%';
            $query->where('roles.title', 'ilike', $needle);
        }
        if ($filters['tier'] !== null) {
            $query->where('companies.tier', $filters['tier']);
        }
        if ($filters['workplace'] !== null) {
            $query->where('roles.workplace_type', $filters['workplace']);
        }
        if ($filters['posted'] !== null) {
            $query->whereRaw(
                'COALESCE(roles.posted_at, roles.created_at) >= ?',
                [CarbonImmutable::now()->subDays(self::POSTED[$filters['posted']])],
            );
        }
        if ($filters['company'] !== null) {
            $query->where('roles.company_id', $filters['company']);
        }

        return $query;
    }

    /**
     * @return array{status: string, q: ?string, tier: ?string, workplace: ?string, posted: ?string, company: ?int}
     */
    private function normalizeFilters(Request $request): array
    {
        $status = (string) $request->query('status', 'open');
        $q = trim((string) $request->query('q', ''));
        $tier = (string) $request->query('tier', '');
        $workplace = (string) $request->query('workplace', '');
        $posted = (string) $request->query('posted', '');
        $company = $request->query('company');

        return [
            'status' => $status === 'dismissed' ? 'dismissed' : 'open',
            'q' => $q === '' ? null : mb_substr($q, 0, 120),
            'tier' => in_array($tier, self::TIERS, true) ? $tier : null,
            'workplace' => in_array($workplace, [WorkplaceType::REMOTE->value, WorkplaceType::HYBRID->value, WorkplaceType::ON_SITE->value], true) ? $workplace : null,
            'posted' => array_key_exists($posted, self::POSTED) ? $posted : null,
            'company' => is_scalar($company) && ctype_digit((string) $company) && (int) $company > 0 ? (int) $company : null,
        ];
    }
}
