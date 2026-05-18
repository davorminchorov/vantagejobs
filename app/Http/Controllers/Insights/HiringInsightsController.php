<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\Company;
use App\Models\CompanySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Inertia\Inertia;
use Inertia\Response;

final readonly class HiringInsightsController
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(): Response
    {
        $since = CarbonImmutable::now()->subDays(90);

        // Per-day totals across all active A-tier companies. This is the
        // global "is the A-tier hiring more or less than last week" view.
        $globalRows = $this->db->table('company_snapshots')
            ->join('companies', 'companies.id', '=', 'company_snapshots.company_id')
            ->where('company_snapshots.snapshot_date', '>=', $since->toDateString())
            ->where('companies.is_active', true)
            ->where('companies.tier', 'A')
            ->groupBy('company_snapshots.snapshot_date')
            ->orderBy('company_snapshots.snapshot_date')
            ->selectRaw('company_snapshots.snapshot_date::text as date,
                         COALESCE(SUM(open_role_count), 0)::int as open_role_count,
                         COALESCE(SUM(listings_count), 0)::int as listings_count')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'open_role_count' => (int) $r->open_role_count,
                'listings_count' => (int) $r->listings_count,
            ])
            ->values();

        // Per-company most-recent + 7-day-ago counts for a sparkline-grade table.
        $perCompany = Company::query()
            ->where('is_active', true)
            ->where('tier', 'A')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Company $c) use ($since): array {
                $series = CompanySnapshot::query()
                    ->where('company_id', $c->id)
                    ->where('snapshot_date', '>=', $since->toDateString())
                    ->orderBy('snapshot_date')
                    ->get(['snapshot_date', 'open_role_count', 'listings_count']);

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'series' => $series->map(fn (CompanySnapshot $s) => [
                        'date' => $s->snapshot_date?->toDateString(),
                        'open_role_count' => $s->open_role_count,
                        'listings_count' => $s->listings_count,
                    ])->values(),
                ];
            })
            ->values();

        return Inertia::render('Insights/Hiring', [
            'global' => $globalRows,
            'companies' => $perCompany,
        ]);
    }
}
