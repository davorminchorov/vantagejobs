<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;

/**
 * Volume cap on per-run NEW listings, per PRD §6.4.5.
 *
 * If a single aggregator run inserts more than `triggerThreshold` listings
 * with status NEW (i.e. ones that survived the auto-dismiss / dedup gates),
 * everything below the top `keepTop` by signal is demoted to OVERFLOW.
 * Prevents the triage queue from becoming overwhelming during big HackerNews
 * months. OVERFLOW listings stay visible under a separate filter.
 *
 * Signal score:
 *   has_comp     +1
 *   is_remote    +1
 *
 * Ties broken by posted_at desc (newer first), then listing.id asc for
 * determinism.
 *
 * Thresholds are constructor-injected so tests can use small numbers
 * (defaults match the PRD: 200 / 50).
 */
final readonly class VolumeCapAction
{
    public function __construct(
        public int $triggerThreshold = 200,
        public int $keepTop = 50,
    ) {}

    /**
     * @param  list<int>  $createdIds  IDs of listings created during this run
     * @return int  number demoted to OVERFLOW
     */
    public function cap(array $createdIds): int
    {
        if ($createdIds === []) {
            return 0;
        }

        $newListings = AggregatorListing::query()
            ->whereIn('id', $createdIds)
            ->where('status', AggregatorListingStatus::NEW->value)
            ->get(['id', 'has_comp', 'parsed_remote_type', 'posted_at']);

        if ($newListings->count() <= $this->triggerThreshold) {
            return 0;
        }

        $scored = $newListings
            ->map(static function (AggregatorListing $listing): array {
                $score = 0;
                if ($listing->has_comp) {
                    $score++;
                }
                if ($listing->parsed_remote_type === 'remote') {
                    $score++;
                }

                return [
                    'id' => (int) $listing->id,
                    'score' => $score,
                    'posted_at' => $listing->posted_at?->getTimestamp() ?? 0,
                ];
            })
            ->sort(static function (array $a, array $b): int {
                // score desc, then posted_at desc, then id asc
                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }
                if ($a['posted_at'] !== $b['posted_at']) {
                    return $b['posted_at'] <=> $a['posted_at'];
                }

                return $a['id'] <=> $b['id'];
            })
            ->values();

        $demoteIds = $scored
            ->slice($this->keepTop)
            ->pluck('id')
            ->all();

        if ($demoteIds === []) {
            return 0;
        }

        AggregatorListing::query()
            ->whereIn('id', $demoteIds)
            ->update(['status' => AggregatorListingStatus::OVERFLOW->value]);

        return count($demoteIds);
    }
}
