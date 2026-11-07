<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\SourceContextFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Triage queue at /discover. Default view = status NEW, newest first.
 * URL-state filters following the Companies/Index pattern: source, status,
 * has_comp, remote_only, seniority, region, free-text q over
 * parsed_company_name / parsed_role_title.
 */
final readonly class IndexController
{
    private const int BODY_TEXT_LIMIT = 10_000;

    /**
     * location_bucket values the parser writes, in display order.
     *
     * @var array<string, string>
     */
    private const array REGIONS = [
        'us' => 'US',
        'americas' => 'Americas',
        'emea' => 'EMEA',
        'apac' => 'APAC',
        'worldwide' => 'Worldwide',
    ];

    public function __construct(private SourceContextFactory $contextFactory) {}

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

        $listings->getCollection()->each(function (AggregatorListing $listing): void {
            $listing->setAttribute('body_text', $this->bodyTextFor($listing));
        });

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
                'seniorities' => $this->seniorityOptions(),
                'regions' => array_map(
                    static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                    array_keys(self::REGIONS),
                    self::REGIONS,
                ),
            ],
        ]);
    }

    /**
     * Plain-text job description for inline reading on the triage page.
     * Null when the listing has no description or its context can't be built.
     */
    private function bodyTextFor(AggregatorListing $listing): ?string
    {
        try {
            $text = $this->contextFactory->buildFor($listing)->bodyText;
        } catch (Throwable) {
            return null;
        }

        // Some feeds escape their HTML twice, so entities survive the
        // context builder's own decoding as literal "&amp;" / "&nbsp;".
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", trim($text)));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::BODY_TEXT_LIMIT) {
            return rtrim(mb_substr($text, 0, self::BODY_TEXT_LIMIT)).'…';
        }

        return $text;
    }

    /**
     * @param  Builder<AggregatorListing>  $query
     * @param  array{status: string, source: ?string, has_comp: bool, remote_only: bool, seniority: ?string, region: ?string, q: ?string}  $filters
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
        if ($filters['seniority'] !== null) {
            $query->where('parsed_seniority', $filters['seniority']);
        }
        if ($filters['region'] !== null) {
            $query->where('location_bucket', $filters['region']);
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
     * @param  array{status: string, source: ?string, has_comp: bool, remote_only: bool, seniority: ?string, region: ?string, q: ?string}  $filters
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
     * Distinct parsed seniority values, most common first. The parser writes
     * free-form labels, so the options come from the data.
     *
     * @return list<string>
     */
    private function seniorityOptions(): array
    {
        return AggregatorListing::query()
            ->whereNotNull('parsed_seniority')
            ->selectRaw('parsed_seniority, COUNT(*) AS row_count')
            ->groupBy('parsed_seniority')
            ->orderByDesc('row_count')
            ->orderBy('parsed_seniority')
            ->pluck('parsed_seniority')
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     status: string,
     *     source: ?string,
     *     has_comp: bool,
     *     remote_only: bool,
     *     seniority: ?string,
     *     region: ?string,
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

        $seniority = trim((string) $request->query('seniority', ''));

        $regionInput = (string) $request->query('region', '');
        $region = array_key_exists($regionInput, self::REGIONS) ? $regionInput : null;

        $q = trim((string) $request->query('q', ''));

        return [
            'status' => $status,
            'source' => $source,
            'has_comp' => $request->boolean('has_comp'),
            'remote_only' => $request->boolean('remote_only'),
            'seniority' => $seniority === '' ? null : mb_substr($seniority, 0, 64),
            'region' => $region,
            'q' => $q === '' ? null : $q,
        ];
    }
}
