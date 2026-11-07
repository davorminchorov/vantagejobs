<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HackerNews moved from two monthly cron entries (3rd + 5th) to the daily
 * aggregator:dispatch-due sweep. Rows still on the old 30-day default would
 * otherwise wait up to a month for their next_run_at, so they get the new
 * daily cadence and are made due immediately. Rows whose cadence was changed
 * by hand are left alone.
 */
return new class extends Migration
{
    private const OLD_CADENCE_MINUTES = 60 * 24 * 30;

    private const NEW_CADENCE_MINUTES = 60 * 24;

    public function up(): void
    {
        DB::table('aggregator_sources')
            ->where('type', 'hackernews')
            ->where('cadence_minutes', self::OLD_CADENCE_MINUTES)
            ->update([
                'cadence_minutes' => self::NEW_CADENCE_MINUTES,
                'next_run_at' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('aggregator_sources')
            ->where('type', 'hackernews')
            ->where('cadence_minutes', self::NEW_CADENCE_MINUTES)
            ->update(['cadence_minutes' => self::OLD_CADENCE_MINUTES]);
    }
};
