<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Source health thresholds
    |--------------------------------------------------------------------------
    |
    | consecutive_failure_alert : number of back-to-back failed runs on a
    |   source before a source-health alert email is raised.
    | confidence_drop_wow_pct   : week-over-week percentage drop in parser
    |   confidence (p50) that triggers a degradation alert.
    |
    */
    'consecutive_failure_alert' => (int) env('SOURCES_CONSECUTIVE_FAILURE_ALERT', 3),

    'confidence_drop_wow_pct' => (int) env('SOURCES_CONFIDENCE_DROP_WOW_PCT', 20),

];
