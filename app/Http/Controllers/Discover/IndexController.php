<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Triage queue at /discover. Default view = status NEW, newest first.
 * URL-state filters following the Companies/Index pattern: source, status,
 * has_comp, remote_only, free-text q over parsed_company_name /
 * parsed_role_title.
 */
final readonly class IndexController
{
    public function __invoke(Request $request): Response
    {
        $filters = $this->normalizeFilters($request);

        $query = $this->applyNonStatusFilters(AggregatorListing::query()->with('source:id,type,name'), $filters)
            ->where('status', $filters['status']);

        $listings = $query
            // Postgres sorts DESC with NULLS FIRST by default, which would
            // bury every dated listing under the undated backlog.
            ->orderByRaw('posted_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Discover/Index', [
            'listings' => Inertia::scroll($listings)->append('data', matchOn: 'id'),
            'filters' => $filters,
            'counts' => $this->statusCounts($filters),
            'options' => [
                'sources' => array_map(static fn (AggregatorSourceType $t): array => [
                    'value' => $t->value,
                    'label' => $t->displayName(),
                ], AggregatorSourceType::cases()),
                'statuses' => array_map(static fn (AggregatorListingStatus $s): string => $s->value, AggregatorListingStatus::cases()),
            ],
        ]);
    }

    /**
     * @param  Builder<AggregatorListing>  $query
     * @param  array{status: string, source: ?string, has_comp: bool, remote_only: bool, q: ?string}  $filters
     * @return Builder<AggregatorListing>
     */
    private function applyNonStatusFilters(Builder $query, array $filters): Builder
    {
        if ($filters['source'] !== null) {
            $query->whereHas('source', fn ($q) => $q->where('type', $filters['source']));
        }
        if ($filters['has_comp'] === true) {
            $query->where('has_comp', true);
        }
        if ($filters['remote_only'] === true) {
            $query->where('parsed_remote_type', 'remote');
        }
        if ($filters['q'] !== null) {
            $needle = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('parsed_company_name', 'ilike', $needle)
                    ->orWhere('parsed_role_title', 'ilike', $needle);
            });
        }

        return $query;
    }

    /**
     * Per-status counts for the tabs and the More dropdown, zero-filled for
     * every AggregatorListingStatus case and scoped by the current
     * non-status filters (mirrors DashboardController::pipelineSummary()).
     *
     * @param  array{status: string, source: ?string, has_comp: bool, remote_only: bool, q: ?string}  $filters
     * @return array<string, int>
     */
    private function statusCounts(array $filters): array
    {
        $counts = $this->applyNonStatusFilters(AggregatorListing::query(), $filters)
            ->selectRaw('status, COUNT(*) AS row_count')
            ->groupBy('status')
            ->pluck('row_count', 'status');

        $result = [];
        foreach (AggregatorListingStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * @return array{
     *     status: string,
     *     source: ?string,
     *     has_comp: bool,
     *     remote_only: bool,
     *     q: ?string,
     * }
     */
    private function normalizeFilters(Request $request): array
    {
        $statusInput = (string) $request->query('status', AggregatorListingStatus::NEW->value);
        $status = AggregatorListingStatus::tryFrom($statusInput)?->value ?? AggregatorListingStatus::NEW->value;

        $sourceInput = (string) $request->query('source', '');
        $source = $sourceInput === '' ? null
            : (AggregatorSourceType::tryFrom($sourceInput)?->value);

        $q = trim((string) $request->query('q', ''));

        return [
            'status' => $status,
            'source' => $source,
            'has_comp' => $request->boolean('has_comp'),
            'remote_only' => $request->boolean('remote_only'),
            'q' => $q === '' ? null : $q,
        ];
    }
}
