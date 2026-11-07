<?php

declare(strict_types=1);

namespace App\Signals\Hiring;

use App\Models\CompanySnapshot;
use Carbon\CarbonImmutable;

/**
 * Daily open-role counts per company from company_snapshots, oldest first,
 * loaded with a single query. Days without a snapshot repeat the previous
 * known count; days before the company's first snapshot in the window are
 * null, so history is never invented.
 */
final class OpenRoleTrends
{
    /**
     * @param  list<int>  $companyIds
     * @return array<int, list<int|null>> Keyed by company id, one entry per day ending today.
     */
    public function daily(array $companyIds, int $days): array
    {
        if ($companyIds === [] || $days < 1) {
            return [];
        }

        $today = CarbonImmutable::today();
        $start = $today->subDays($days - 1);

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
            for ($i = 0; $i < $days; $i++) {
                $last = $counts[$start->addDays($i)->toDateString()] ?? $last;
                $series[] = $last;
            }
            $trends[$companyId] = $series;
        }

        return $trends;
    }

    /**
     * Change between the last value and the value `$daysBack` days earlier,
     * or null when either side has no snapshot yet.
     *
     * @param  list<int|null>  $series
     */
    public static function change(array $series, int $daysBack): ?int
    {
        $lastIndex = count($series) - 1;
        $earlierIndex = $lastIndex - $daysBack;
        if ($earlierIndex < 0) {
            return null;
        }

        $last = $series[$lastIndex];
        $earlier = $series[$earlierIndex];

        return $last === null || $earlier === null ? null : $last - $earlier;
    }
}
