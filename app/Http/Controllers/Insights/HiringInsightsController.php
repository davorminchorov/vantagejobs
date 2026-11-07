<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Signals\Hiring\OpenRoleTrends;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hiring momentum across tracked companies: open roles now, how that moved
 * over 7 and 30 days, roles opened and closed this week, and a per-company
 * leaderboard. Trends come from daily company_snapshots; "open now" and the
 * weekly counts are live.
 */
final readonly class HiringInsightsController
{
    private const int TREND_DAYS = 90;

    /** @var list<string> */
    private const array TIERS = ['A', 'B', 'C'];

    public function __construct(private OpenRoleTrends $trends) {}

    public function __invoke(Request $request): Response
    {
        $tier = strtoupper((string) $request->query('tier', ''));
        $tier = in_array($tier, self::TIERS, true) ? $tier : null;

        /** @var Collection<int, Company> $companies */
        $companies = Company::query()
            ->where('is_active', true)
            ->when($tier !== null, fn ($query) => $query->where('tier', $tier))
            ->orderBy('name')
            ->get(['id', 'name', 'tier']);

        $ids = $companies->pluck('id')->all();
        $weekAgo = CarbonImmutable::now()->subDays(7);

        $openNow = $this->countBy(Role::query()->where('is_open', true), $ids);
        $newThisWeek = $this->countBy(Role::query()->where('created_at', '>=', $weekAgo), $ids);
        $closedThisWeek = $this->countBy(
            Event::query()->where('type', EventType::ROLE_CLOSED->value)->where('occurred_at', '>=', $weekAgo),
            $ids,
        );
        $trends = $this->trends->daily($ids, self::TREND_DAYS);

        $rows = $companies->map(function (Company $company) use ($trends, $openNow, $newThisWeek, $closedThisWeek): array {
            $open = $openNow[$company->id] ?? 0;
            $series = $trends[$company->id] ?? array_fill(0, self::TREND_DAYS, null);
            // Today's point is the live count, so the change columns match "Open".
            $series[count($series) - 1] = $open;

            return [
                'id' => $company->id,
                'name' => $company->name,
                'tier' => $company->tier,
                'open_now' => $open,
                'trend' => $series,
                'change_7d' => OpenRoleTrends::change($series, 7),
                'change_30d' => OpenRoleTrends::change($series, 30),
                'new_7d' => $newThisWeek[$company->id] ?? 0,
                'closed_7d' => $closedThisWeek[$company->id] ?? 0,
            ];
        })->values();

        $total = $this->totalSeries($rows->pluck('trend')->all());

        return Inertia::render('Insights/Hiring', [
            'filters' => ['tier' => $tier ?? 'all'],
            'summary' => [
                'open_now' => array_sum($openNow),
                'change_7d' => $this->totalChange($rows->all(), 'change_7d'),
                'change_30d' => $this->totalChange($rows->all(), 'change_30d'),
                'new_7d' => array_sum($newThisWeek),
                'closed_7d' => array_sum($closedThisWeek),
                'companies_hiring' => $rows->where('open_now', '>', 0)->count(),
                'companies_total' => $rows->count(),
            ],
            'series' => $total,
            'companies' => $rows,
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Role>|\Illuminate\Database\Eloquent\Builder<Event>  $query
     * @param  list<int>  $companyIds
     * @return array<int, int>
     */
    private function countBy($query, array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        return $query
            ->whereIn('company_id', $companyIds)
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as aggregate')
            ->pluck('aggregate', 'company_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Sum of every company's daily count, oldest first. Days before any
     * company has a snapshot are left out.
     *
     * @param  list<list<int|null>>  $trends
     * @return list<array{date: string, open_role_count: int}>
     */
    private function totalSeries(array $trends): array
    {
        $start = CarbonImmutable::today()->subDays(self::TREND_DAYS - 1);
        $points = [];

        for ($day = 0; $day < self::TREND_DAYS; $day++) {
            $values = array_filter(array_column($trends, $day), static fn (?int $value): bool => $value !== null);
            if ($values === []) {
                continue;
            }
            $points[] = [
                'date' => $start->addDays($day)->toDateString(),
                'open_role_count' => array_sum($values),
            ];
        }

        return $points;
    }

    /**
     * Sum of the per-company changes that have enough history, or null when
     * no company has a snapshot that far back.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function totalChange(array $rows, string $key): ?int
    {
        $changes = array_filter(array_column($rows, $key), static fn (mixed $value): bool => $value !== null);

        return $changes === [] ? null : (int) array_sum($changes);
    }
}
