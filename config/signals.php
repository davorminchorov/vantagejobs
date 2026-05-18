<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 4 signal thresholds and weights
|--------------------------------------------------------------------------
|
| Knobs for ghost-job detection and the company quality score. Edit here
| rather than scattering numbers across services. Promote any value to a
| Setting if it turns out to need per-tenant tuning.
|
*/

return [

    'ghost' => [
        // Heuristic 1: posted long ago and still active.
        'posted_age_days_threshold' => 60,

        // Heuristic 2: same requisition_id reappears in 2+ NEW_ROLE Events.
        // The re-post-gap is enforced by the boomerang hash, not here.
        'requisition_reappearance_threshold' => 2,

        // Heuristic 3: company in layoffs.fyi window vs still posting.
        'layoff_proximity_days' => 30,

        // Heuristic 4: same role hash shows up across N+ aggregator sources
        // but no Role row exists on the company's own ATS.
        'aggregator_diversity_threshold' => 3,
    ],

    'quality_score' => [
        // Baseline before adjustments. Companies start at "neutral".
        'baseline' => 75,

        // Positive contributors. Caps prevent any single dimension from
        // running away.
        'funding_recency_max' => 15,
        'github_activity_max' => 15,
        'blog_cadence_max' => 10,
        'hiring_volume_stability_max' => 20,
        'comp_transparency_max' => 10,

        // Penalties (subtracted; quote as positives, applied as -X).
        'layoff_penalty' => 20,
        'ghost_density_penalty' => 15,
        'no_eng_presence_penalty' => 10,

        // Score is clamped to [1, 100].
    ],

];
