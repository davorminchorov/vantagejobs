<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
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

        $query = AggregatorListing::query()
            ->with('source:id,type,name')
            ->where('status', $filters['status']);

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

        $listings = $query
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Discover/Index', [
            'listings' => $listings,
            'filters' => $filters,
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
