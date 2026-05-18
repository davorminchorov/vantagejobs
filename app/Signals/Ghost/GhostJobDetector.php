<?php

declare(strict_types=1);

namespace App\Signals\Ghost;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Applies the four PRD §6.3.6 ghost-job heuristics against a listing and
 * returns the list of reason codes that matched. Reason codes drive the
 * explainability UI: the user sees *why* a role is flagged, not a black-
 * box "maybe ghost" label.
 *
 * Reason codes:
 *   - `posted_age_gt_60d`     posted ≥60 days ago and still status NEW/KEPT/LINKED
 *   - `requisition_reposted`  same requisition_id appears on ≥2 NEW_ROLE Events for the company
 *   - `layoffs_within_30d`    company has a layoff Event within the last 30 days
 *   - `aggregator_breadth_3`  same role_hash on ≥3 distinct aggregator sources, no matching open Role on the company's ATS
 *
 * Thresholds are read from config/signals.php — promote any to a Setting
 * if they need per-tenant tuning.
 */
final readonly class GhostJobDetector
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return list<string> the reason codes that matched
     */
    public function detect(AggregatorListing $listing): array
    {
        if (in_array($listing->status, [
            AggregatorListingStatus::DISMISSED,
            AggregatorListingStatus::AUTO_DISMISSED,
            AggregatorListingStatus::DUPLICATE,
        ], true)) {
            return [];
        }

        $cfg = config('signals.ghost');
        $reasons = [];

        // H1: posted long ago and still active.
        if ($listing->posted_at !== null) {
            $ageDays = CarbonImmutable::now()->diffInDays($listing->posted_at, true);
            if ($ageDays >= (int) $cfg['posted_age_days_threshold']) {
                $reasons[] = 'posted_age_gt_'.((int) $cfg['posted_age_days_threshold']).'d';
            }
        }

        // H2: requisition_id reappearing — requires both the listing and
        // ≥2 NEW_ROLE Events that reference this requisition.
        if (is_string($listing->requisition_id) && $listing->requisition_id !== '' && $listing->matched_company_id !== null) {
            $count = $this->db->table('events')
                ->where('company_id', $listing->matched_company_id)
                ->where('type', EventType::NEW_ROLE->value)
                ->where('body', 'like', '%"requisition_id":"'.$listing->requisition_id.'"%')
                ->count();
            if ($count >= (int) $cfg['requisition_reappearance_threshold']) {
                $reasons[] = 'requisition_reposted';
            }
        }

        // H3: company in layoffs.fyi window vs still posting.
        if ($listing->matched_company_id !== null) {
            $cutoff = CarbonImmutable::now()->subDays((int) $cfg['layoff_proximity_days']);
            $recentLayoff = $this->db->table('events')
                ->where('company_id', $listing->matched_company_id)
                ->where('type', EventType::LAYOFF->value)
                ->where('occurred_at', '>=', $cutoff)
                ->exists();
            if ($recentLayoff) {
                $reasons[] = 'layoffs_within_'.((int) $cfg['layoff_proximity_days']).'d';
            }
        }

        // H4: same role_hash across ≥N aggregator sources but no Role on
        // the company's own ATS. We approximate "missing from ATS" by
        // checking the `roles` table for the company; if it has no row
        // for that title/hash, count that as missing.
        if (is_string($listing->role_hash) && $listing->role_hash !== '' && $listing->matched_company_id !== null) {
            $sources = (int) $this->db->table('aggregator_listings')
                ->where('role_hash', $listing->role_hash)
                ->whereNotIn('status', [
                    AggregatorListingStatus::DISMISSED->value,
                    AggregatorListingStatus::AUTO_DISMISSED->value,
                    AggregatorListingStatus::DUPLICATE->value,
                ])
                ->distinct('source_id')
                ->count('source_id');

            if ($sources >= (int) $cfg['aggregator_diversity_threshold']) {
                $hasAtsRole = $this->db->table('roles')
                    ->where('company_id', $listing->matched_company_id)
                    ->where('is_open', true)
                    ->whereRaw('LOWER(title) = ?', [mb_strtolower($listing->parsed_role_title ?? '')])
                    ->exists();
                if (! $hasAtsRole) {
                    $reasons[] = 'aggregator_breadth_'.((int) $cfg['aggregator_diversity_threshold']);
                }
            }
        }

        return $reasons;
    }
}
