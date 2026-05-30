<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 5 pipeline, offer and outreach knobs
|--------------------------------------------------------------------------
|
| Tunable values for the application pipeline, offer comparison and the
| outreach follow-up queue. Edit here rather than scattering numbers across
| services. Promote any value to a Setting if it ever needs per-tenant tuning.
|
*/

return [

    // Days of inactivity before a role card or contact is flagged for follow-up.
    'follow_up_threshold_days' => 7,

    // Default reminder lead time (minutes before a calendar event) when the
    // user does not specify one.
    'default_reminder_minutes' => 30,

    // Seconds the public iCal feed is cached per token.
    'ical_cache_ttl' => 300,

    /*
    | Static USD conversion rates for currency-aware total-comp comparison.
    | No live FX — these are deterministic snapshots. Rate is "1 unit of the
    | currency = N USD". Add entries as new offer currencies appear.
    */
    'currency_rates_usd' => [
        'USD' => 1.0,
        'EUR' => 1.08,
        'GBP' => 1.27,
        'CAD' => 0.73,
        'AUD' => 0.66,
        'CHF' => 1.12,
        'JPY' => 0.0064,
        'INR' => 0.012,
        'SGD' => 0.74,
        'SEK' => 0.095,
    ],

];
