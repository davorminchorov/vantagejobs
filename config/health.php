<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Liveness max scrape age
    |--------------------------------------------------------------------------
    |
    | Maximum age (hours) of the most recent finished ScrapeRun for the public
    | liveness endpoint to report healthy. Older than this and /up returns 503.
    |
    */
    'liveness_max_scrape_age_hours' => (int) env('HEALTH_LIVENESS_MAX_SCRAPE_AGE_HOURS', 48),

];
