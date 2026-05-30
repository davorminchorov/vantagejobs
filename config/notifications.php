<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Daily digest send time
    |--------------------------------------------------------------------------
    |
    | Wall-clock time (HH:MM) in the recipient timezone when the daily digest
    | is dispatched. Overridable per-tenant via the `digest_send_time` Setting.
    |
    */
    'digest_send_time' => env('NOTIFICATIONS_DIGEST_SEND_TIME', '07:00'),

    /*
    |--------------------------------------------------------------------------
    | Timezone fallback
    |--------------------------------------------------------------------------
    |
    | Used when the recipient User has no timezone set. Drives digest
    | scheduling and the do-not-disturb window.
    |
    */
    'timezone' => env('NOTIFICATIONS_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Do-not-disturb window
    |--------------------------------------------------------------------------
    |
    | Wall-clock window (recipient timezone) during which non-digest emails
    | (A-tier instant alerts) are deferred to the next allowed minute rather
    | than dropped.
    |
    */
    'dnd_start' => env('NOTIFICATIONS_DND_START', '22:00'),
    'dnd_end' => env('NOTIFICATIONS_DND_END', '07:00'),

    /*
    |--------------------------------------------------------------------------
    | Skip empty digests
    |--------------------------------------------------------------------------
    |
    | When true, the daily digest is suppressed if nothing new happened in the
    | window, preventing notification fatigue.
    |
    */
    'digest_skip_empty' => (bool) env('NOTIFICATIONS_DIGEST_SKIP_EMPTY', true),

    /*
    |--------------------------------------------------------------------------
    | A-tier alert throttle
    |--------------------------------------------------------------------------
    |
    | Minimum minutes between instant A-tier alert emails for the same company,
    | enforced against the notification_log ledger.
    |
    */
    'a_tier_throttle_minutes' => (int) env('NOTIFICATIONS_A_TIER_THROTTLE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Weekly summary day
    |--------------------------------------------------------------------------
    |
    | Day of week the weekly summary is sent.
    |
    */
    'weekly_summary_day' => env('NOTIFICATIONS_WEEKLY_SUMMARY_DAY', 'sunday'),

    /*
    |--------------------------------------------------------------------------
    | A-tier stale threshold
    |--------------------------------------------------------------------------
    |
    | Days an A-tier company can go without activity before it surfaces as
    | stale in the weekly summary.
    |
    */
    'a_tier_stale_days' => (int) env('NOTIFICATIONS_A_TIER_STALE_DAYS', 7),

];
