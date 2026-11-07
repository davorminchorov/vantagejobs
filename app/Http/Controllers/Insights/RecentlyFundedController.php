<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\Company;
use App\Models\FundingAnnouncement;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Signals\Funding\FundingFeedSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recently funded: companies that announced a round in startup news feeds
 * (signals:funding:feed), as a discovery list. Headlines about the same
 * company are grouped; companies already on the watch list are hidden by
 * default. "Track" hands the company to the add-company form.
 */
final readonly class RecentlyFundedController
{
    /** @var array<string, list<string>> */
    private const array REGION_SOURCES = [
        'us' => ['us'],
        'uk' => ['uk'],
        'europe' => ['europe', 'uk'],
        'global' => ['global'],
    ];

    /** @var array<string, list<string>> */
    private const array ROUND_STAGES = [
        'seed' => ['Pre-seed', 'Seed'],
        'early' => ['Series A', 'Series B'],
        'growth' => ['Series C', 'Series D', 'Series E', 'Series F', 'Series G', 'Series H'],
    ];

    public function __construct(private CompanyKeyNormalizer $normalizer) {}

    public function __invoke(Request $request): Response
    {
        $region = (string) $request->query('region', 'all');
        $region = array_key_exists($region, self::REGION_SOURCES) ? $region : 'all';
        $round = (string) $request->query('round', 'all');
        $round = array_key_exists($round, self::ROUND_STAGES) ? $round : 'all';
        $hideTracked = $request->query('tracked', 'hide') !== 'show';

        /** @var Collection<int, FundingAnnouncement> $rows */
        $rows = FundingAnnouncement::query()
            ->whereIn('status', [FundingAnnouncement::STATUS_NEW, FundingAnnouncement::STATUS_TRACKED])
            ->where('published_at', '>=', CarbonImmutable::now()->subDays(FundingAnnouncement::MAX_AGE_DAYS))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $tracked = $this->trackedCompanies();

        $groups = $rows
            ->groupBy(fn (FundingAnnouncement $row): string => $row->company_key ?? 'announcement:'.$row->id)
            ->map(fn (Collection $announcements, string $key): array => $this->group($key, $announcements, $tracked))
            ->filter(function (array $group) use ($region, $round, $hideTracked): bool {
                if ($hideTracked && $group['tracked_company'] !== null) {
                    return false;
                }
                if ($region !== 'all' && array_intersect($group['regions'], self::REGION_SOURCES[$region]) === []) {
                    return false;
                }

                return $round === 'all' || in_array($group['stage'], self::ROUND_STAGES[$round], true);
            })
            ->sortByDesc('latest_published_at')
            ->values();

        return Inertia::render('Insights/RecentlyFunded', [
            'filters' => [
                'region' => $region,
                'round' => $round,
                'tracked' => $hideTracked ? 'hide' : 'show',
            ],
            'groups' => $groups,
            'max_age_days' => FundingAnnouncement::MAX_AGE_DAYS,
        ]);
    }

    /**
     * @param  Collection<int, FundingAnnouncement>  $announcements  Newest first.
     * @param  array<string, array{id: int, name: string}>  $tracked
     * @return array<string, mixed>
     */
    private function group(string $key, Collection $announcements, array $tracked): array
    {
        /** @var FundingAnnouncement $newest */
        $newest = $announcements->first();
        $withAmount = $announcements->first(fn (FundingAnnouncement $row): bool => $row->amount_label !== null);
        $withStage = $announcements->first(fn (FundingAnnouncement $row): bool => $row->stage !== null);
        $linked = $announcements->first(fn (FundingAnnouncement $row): bool => $row->company_id !== null);

        $trackedCompany = $tracked[$key] ?? null;
        if ($trackedCompany === null && $linked !== null && $linked->company !== null) {
            $trackedCompany = ['id' => $linked->company->id, 'name' => $linked->company->name];
        }

        $sources = $announcements
            ->map(fn (FundingAnnouncement $row): ?FundingFeedSource => FundingFeedSource::tryFrom($row->source))
            ->filter();

        return [
            'key' => $key,
            'announcement_id' => $newest->id,
            'company_name' => $announcements->first(fn (FundingAnnouncement $row): bool => $row->company_name !== null)?->company_name,
            'stage' => $withStage?->stage,
            'amount_label' => $withAmount?->amount_label,
            'regions' => $sources->map(fn (FundingFeedSource $source): string => $source->region())->unique()->values()->all(),
            'latest_published_at' => $newest->published_at?->toIso8601String(),
            'tracked_company' => $trackedCompany,
            'headlines' => $announcements->map(fn (FundingAnnouncement $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'url' => $row->url,
                'source' => FundingFeedSource::tryFrom($row->source)?->label() ?? $row->source,
                'published_at' => $row->published_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Watch-list companies keyed by normalised name, matching how
     * announcements store company_key.
     *
     * @return array<string, array{id: int, name: string}>
     */
    private function trackedCompanies(): array
    {
        $byKey = [];
        foreach (Company::query()->get(['id', 'name']) as $company) {
            $key = $this->normalizer->normalize($company->name);
            if ($key !== null) {
                $byKey[$key] ??= ['id' => $company->id, 'name' => $company->name];
            }
        }

        return $byKey;
    }
}
