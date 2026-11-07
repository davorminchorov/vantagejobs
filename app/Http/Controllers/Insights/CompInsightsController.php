<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Signals\Comp\SalaryFilters;
use App\Signals\Comp\SalaryInsights;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salary explorer: posted pay ranges from aggregator listings, filtered by
 * discipline, seniority, region and time window, with percentiles per
 * seniority level and per region, and the newest listings behind them.
 */
final readonly class CompInsightsController
{
    public function __construct(
        private SalaryInsights $insights,
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request): Response
    {
        $filters = SalaryFilters::fromRequest($request);
        $data = $this->insights->explore($this->tenantContext->currentOrFail()->id, $filters);

        return Inertia::render('Insights/Comp', [
            'filters' => $filters->toArray(),
            'options' => [
                'disciplines' => self::options(SalaryInsights::DISCIPLINES),
                'levels' => self::options(array_diff_key(SalaryInsights::LEVELS, ['unspecified' => true])),
                'regions' => self::options(SalaryInsights::REGIONS),
            ],
            'bounds' => [
                'min_annual_usd' => SalaryInsights::MIN_ANNUAL_USD,
                'max_annual_usd' => SalaryInsights::MAX_ANNUAL_USD,
            ],
            ...$data,
        ]);
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        return array_map(
            static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );
    }
}
