<?php

declare(strict_types=1);

namespace App\Signals\Quality;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Computes the per-company quality score plus its explainable breakdown.
 *
 * The score lives in [1, 100]; companies start at the configured baseline
 * (default 75) and gain or lose points from independent dimensions. Every
 * contribution is recorded in the breakdown payload so the "why this
 * score" UI can render the math line-by-line — per kickoff requirement
 * the score must be explainable, not a black box.
 *
 * Weights live in `config/signals.php` (quality_score.*). All formulas
 * are documented inline so future tweaks are auditable.
 *
 * Returns shape:
 *   [
 *     'score' => 0..100,
 *     'breakdown' => [
 *       'funding_recency'         => ['weight' => 15,  'contribution' => 12, 'value' => ['months_since' => 6]],
 *       'github_activity'         => ['weight' => 15,  'contribution' => 7,  'value' => ['github_score' => 47]],
 *       'blog_cadence'            => ['weight' => 10,  'contribution' => 5,  'value' => ['posts_90d' => 4]],
 *       'hiring_volume_stability' => ['weight' => 20,  'contribution' => 12, 'value' => ['change_30d' => -0.05]],
 *       'comp_transparency'       => ['weight' => 10,  'contribution' => 3,  'value' => ['ratio' => 0.32]],
 *       'layoff_penalty'          => ['weight' => -20, 'contribution' => -20,'value' => ['within_90d' => true]],
 *       'ghost_density_penalty'   => ['weight' => -15, 'contribution' => -4, 'value' => ['ratio' => 0.13]],
 *       'no_eng_presence_penalty' => ['weight' => -10, 'contribution' => 0,  'value' => ['has_any' => true]],
 *     ],
 *   ]
 */
final readonly class QualityScorer
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return array{score: int, breakdown: array<string, array{weight: int, contribution: int, value: array<string, mixed>}>}
     */
    public function compute(Company $company): array
    {
        $cfg = config('signals.quality_score');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $tenantId = (int) $company->tenant_id;
        $companyId = (int) $company->id;

        $breakdown = [];
        $total = (int) $cfg['baseline'];

        // ------- POSITIVE CONTRIBUTORS -------

        // Funding recency — full credit if funded <12mo ago, decay linearly to 0 by 24mo.
        $months = $company->funding_last_raised_at !== null
            ? max(0, $today->diffInMonths($company->funding_last_raised_at, true))
            : null;
        $fundingW = (int) $cfg['funding_recency_max'];
        $fundingContribution = match (true) {
            $months === null => 0,
            $months <= 12.0 => $fundingW,
            $months >= 24.0 => 0,
            default => (int) round($fundingW * (1 - ($months - 12) / 12)),
        };
        $breakdown['funding_recency'] = [
            'weight' => $fundingW,
            'contribution' => $fundingContribution,
            'value' => ['months_since_last_raise' => $months],
        ];
        $total += $fundingContribution;

        // GitHub activity — today's snapshot's github_score scaled 0–100 → 0–weight.
        $githubScore = $this->db->table('company_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('snapshot_date', $today->toDateString())
            ->value('github_score');
        $githubW = (int) $cfg['github_activity_max'];
        $githubContribution = $githubScore !== null
            ? (int) round(((int) $githubScore / 100) * $githubW)
            : 0;
        $breakdown['github_activity'] = [
            'weight' => $githubW,
            'contribution' => $githubContribution,
            'value' => ['github_score' => $githubScore !== null ? (int) $githubScore : null],
        ];
        $total += $githubContribution;

        // Blog cadence — posts in last 90 days. 8+ = full credit.
        $posts90 = (int) $this->db->table('company_blog_posts')
            ->where('company_id', $companyId)
            ->where('published_at', '>=', $today->subDays(90))
            ->count();
        $blogW = (int) $cfg['blog_cadence_max'];
        $blogContribution = min($blogW, (int) round(($posts90 / 8) * $blogW));
        $breakdown['blog_cadence'] = [
            'weight' => $blogW,
            'contribution' => $blogContribution,
            'value' => ['posts_90d' => $posts90],
        ];
        $total += $blogContribution;

        // Hiring volume stability — slope from 30d-ago avg to today's avg.
        // Stable or growing = full credit; -50% or worse = 0; linear between.
        $stabilityW = (int) $cfg['hiring_volume_stability_max'];
        $oldAvg = (float) $this->db->table('company_snapshots')
            ->where('company_id', $companyId)
            ->where('snapshot_date', '>=', $today->subDays(30)->toDateString())
            ->where('snapshot_date', '<', $today->subDays(20)->toDateString())
            ->avg($this->db->raw('open_role_count + listings_count'));
        $newAvg = (float) $this->db->table('company_snapshots')
            ->where('company_id', $companyId)
            ->where('snapshot_date', '>=', $today->subDays(10)->toDateString())
            ->avg($this->db->raw('open_role_count + listings_count'));
        $change = $oldAvg > 0 ? ($newAvg - $oldAvg) / $oldAvg : 0;
        $stabilityContribution = match (true) {
            $change >= 0 => $stabilityW,
            $change <= -0.5 => 0,
            default => (int) round($stabilityW * (1 + $change / 0.5)),
        };
        $breakdown['hiring_volume_stability'] = [
            'weight' => $stabilityW,
            'contribution' => $stabilityContribution,
            'value' => [
                'avg_10d' => round($newAvg, 2),
                'avg_30d_to_20d' => round($oldAvg, 2),
                'change' => round($change, 3),
            ],
        ];
        $total += $stabilityContribution;

        // Comp transparency — proportion of listings with has_comp=true.
        $compW = (int) $cfg['comp_transparency_max'];
        $listingsTotal = (int) $this->db->table('aggregator_listings')
            ->where('matched_company_id', $companyId)
            ->whereNotIn('status', [
                AggregatorListingStatus::DISMISSED->value,
                AggregatorListingStatus::AUTO_DISMISSED->value,
                AggregatorListingStatus::DUPLICATE->value,
            ])
            ->count();
        $compListings = (int) $this->db->table('aggregator_listings')
            ->where('matched_company_id', $companyId)
            ->where('has_comp', true)
            ->whereNotIn('status', [
                AggregatorListingStatus::DISMISSED->value,
                AggregatorListingStatus::AUTO_DISMISSED->value,
                AggregatorListingStatus::DUPLICATE->value,
            ])
            ->count();
        $compRatio = $listingsTotal > 0 ? $compListings / $listingsTotal : 0.0;
        $compContribution = $listingsTotal > 0
            ? (int) round(min(1.0, $compRatio / 0.8) * $compW)
            : 0;
        $breakdown['comp_transparency'] = [
            'weight' => $compW,
            'contribution' => $compContribution,
            'value' => ['ratio' => round($compRatio, 3), 'sample_size' => $listingsTotal],
        ];
        $total += $compContribution;

        // ------- PENALTIES -------

        // Layoff within last 90 days.
        $layoffPenalty = -((int) $cfg['layoff_penalty']);
        $hasRecentLayoff = $this->db->table('events')
            ->where('company_id', $companyId)
            ->where('type', EventType::LAYOFF->value)
            ->where('occurred_at', '>=', $today->subDays(90))
            ->exists();
        $layoffContribution = $hasRecentLayoff ? $layoffPenalty : 0;
        $breakdown['layoff_penalty'] = [
            'weight' => $layoffPenalty,
            'contribution' => $layoffContribution,
            'value' => ['within_90d' => $hasRecentLayoff],
        ];
        $total += $layoffContribution;

        // Ghost density penalty — proportion of ghost_flag=true listings.
        $ghostPenalty = -((int) $cfg['ghost_density_penalty']);
        $ghostCount = (int) $this->db->table('aggregator_listings')
            ->where('matched_company_id', $companyId)
            ->where('ghost_flag', true)
            ->count();
        $ghostRatio = $listingsTotal > 0 ? $ghostCount / $listingsTotal : 0.0;
        $ghostContribution = $listingsTotal > 0
            ? (int) round(min(1.0, $ghostRatio / 0.5) * $ghostPenalty)
            : 0;
        $breakdown['ghost_density_penalty'] = [
            'weight' => $ghostPenalty,
            'contribution' => $ghostContribution,
            'value' => ['ratio' => round($ghostRatio, 3), 'ghost_count' => $ghostCount, 'total' => $listingsTotal],
        ];
        $total += $ghostContribution;

        // No eng presence — no github_org AND no eng_blog_rss_url.
        $noPresencePenalty = -((int) $cfg['no_eng_presence_penalty']);
        $hasAny = ! empty($company->github_org) || ! empty($company->eng_blog_rss_url);
        $noPresenceContribution = $hasAny ? 0 : $noPresencePenalty;
        $breakdown['no_eng_presence_penalty'] = [
            'weight' => $noPresencePenalty,
            'contribution' => $noPresenceContribution,
            'value' => ['has_any' => $hasAny],
        ];
        $total += $noPresenceContribution;

        // Clamp to [1, 100].
        $score = max(1, min(100, $total));

        return [
            'score' => $score,
            'breakdown' => $breakdown,
        ];
    }
}
