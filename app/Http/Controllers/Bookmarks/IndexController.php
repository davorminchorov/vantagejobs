<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bookmarks;

use App\Models\AggregatorListing;
use App\Models\Bookmark;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /bookmarks. Lists every bookmarked listing, sortable by
 * created_at (newest first, default), posted_at (newest first), or
 * expires_at (soonest first).
 *
 * Only aggregator_listing bookmarks are surfaced for now — Role
 * bookmarks (PRD §6.6.2 "on any role card") wait for the role bookmark
 * button to ship.
 */
final readonly class IndexController
{
    private const VALID_SORTS = ['added', 'posted', 'expires'];

    public function __invoke(Request $request): Response
    {
        $sort = (string) $request->query('sort', 'added');
        if (! in_array($sort, self::VALID_SORTS, true)) {
            $sort = 'added';
        }

        $bookmarks = Bookmark::query()
            ->where('bookmarkable_type', 'aggregator_listing')
            ->paginate(25)
            ->withQueryString();

        // Hydrate listing rows in one go.
        $listingIds = $bookmarks->getCollection()->pluck('bookmarkable_id')->all();
        $listings = AggregatorListing::query()
            ->with('source:id,type,name')
            ->whereIn('id', $listingIds)
            ->get()
            ->keyBy('id');

        $rows = $bookmarks->getCollection()
            ->map(static function (Bookmark $bookmark) use ($listings): array {
                $listing = $listings->get($bookmark->bookmarkable_id);

                return [
                    'id' => $bookmark->id,
                    'created_at' => $bookmark->created_at?->toIso8601String(),
                    'expires_at' => $bookmark->expires_at?->toIso8601String(),
                    'expires_warned_at' => $bookmark->expires_warned_at?->toIso8601String(),
                    'listing' => $listing instanceof AggregatorListing ? [
                        'id' => $listing->id,
                        'parsed_company_name' => $listing->parsed_company_name,
                        'parsed_role_title' => $listing->parsed_role_title,
                        'parsed_remote_type' => $listing->parsed_remote_type,
                        'location_bucket' => $listing->location_bucket,
                        'parsed_comp_min_usd' => $listing->parsed_comp_min_usd,
                        'parsed_comp_max_usd' => $listing->parsed_comp_max_usd,
                        'has_comp' => $listing->has_comp,
                        'stack_tags' => $listing->stack_tags,
                        'url' => $listing->url,
                        'posted_at' => $listing->posted_at?->toIso8601String(),
                        'source' => $listing->source ? [
                            'type' => $listing->source->type->value,
                            'name' => $listing->source->name,
                        ] : null,
                    ] : null,
                ];
            });

        $sorted = $this->sort($rows, $sort);
        $bookmarks->setCollection($sorted);

        return Inertia::render('Bookmarks/Index', [
            'bookmarks' => $bookmarks,
            'sort' => $sort,
            'options' => [
                'sorts' => self::VALID_SORTS,
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function sort($rows, string $sort)
    {
        return match ($sort) {
            'posted' => $rows->sortByDesc(static fn (array $r): string => (string) ($r['listing']['posted_at'] ?? ''))->values(),
            'expires' => $rows->sortBy(static fn (array $r): string => (string) $r['expires_at'])->values(),
            default => $rows->sortByDesc(static fn (array $r): string => (string) $r['created_at'])->values(),
        };
    }
}
