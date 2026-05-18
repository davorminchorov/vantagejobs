<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexCompaniesController
{
    public function __invoke(Request $request): Response
    {
        $filters = $this->normalizeFilters($request);

        $query = Company::query()
            ->withCount(['roles as open_roles_count' => fn ($q) => $q->where('is_open', true)]);

        if ($filters['tier'] !== null) {
            $query->where('tier', $filters['tier']);
        }
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['ats_type'] !== null) {
            $query->where('ats_type', $filters['ats_type']);
        }
        if ($filters['has_open_roles'] === true) {
            $query->whereHas('roles', fn ($q) => $q->where('is_open', true));
        } elseif ($filters['has_open_roles'] === false) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('is_open', true));
        }

        $this->applyLastCheckedRange($query, $filters['last_checked']);
        if ($filters['q'] !== null) {
            $needle = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'ilike', $needle)
                    ->orWhere('domain', 'ilike', $needle)
                    ->orWhere('ats_slug', 'ilike', $needle);
            });
        }

        $companies = $query
            ->orderBy('tier')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'filters' => $filters,
            'options' => [
                'tiers' => ['A', 'B', 'C', 'graveyard'],
                'statuses' => ['researching', 'watching', 'applied', 'in_process', 'rejected', 'offer', 'passed'],
                'ats_types' => array_map(fn (AtsType $t) => $t->value, AtsType::cases()),
                'last_checked' => ['never', 'last_24h', 'last_7d', 'last_30d', 'older_than_30d'],
            ],
        ]);
    }

    /**
     * @return array{tier: ?string, status: ?string, ats_type: ?string, has_open_roles: ?bool, last_checked: ?string, q: ?string}
     */
    private function normalizeFilters(Request $request): array
    {
        $blankToNull = fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        $hasOpenRoles = $request->query('has_open_roles');
        $hasOpenRolesBool = match ($hasOpenRoles) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };

        return [
            'tier' => $blankToNull($request->query('tier')),
            'status' => $blankToNull($request->query('status')),
            'ats_type' => $blankToNull($request->query('ats_type')),
            'has_open_roles' => $hasOpenRolesBool,
            'last_checked' => $blankToNull($request->query('last_checked')),
            'q' => $blankToNull($request->query('q')),
        ];
    }

    private function applyLastCheckedRange(\Illuminate\Database\Eloquent\Builder $query, ?string $range): void
    {
        if ($range === null) {
            return;
        }

        $now = CarbonImmutable::now();

        match ($range) {
            'never' => $query->whereNull('last_checked_at'),
            'last_24h' => $query->where('last_checked_at', '>=', $now->subDay()),
            'last_7d' => $query->where('last_checked_at', '>=', $now->subDays(7)),
            'last_30d' => $query->where('last_checked_at', '>=', $now->subDays(30)),
            'older_than_30d' => $query->where('last_checked_at', '<', $now->subDays(30)),
            default => null,
        };
    }
}
