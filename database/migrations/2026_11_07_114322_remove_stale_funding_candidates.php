<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * signals:funding:news now skips articles published more than 12 months
 * ago (FundingCandidate::MAX_AGE_MONTHS). Remove the unreviewed candidates
 * that the first backfill stored before that rule existed. Confirmed and
 * dismissed rows are kept as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('funding_candidates')
            ->where('status', 'new')
            ->where(function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<', now()->subMonths(12));
            })
            ->delete();
    }

    public function down(): void
    {
        // Deleted rows come back only if a scan stores them again.
    }
};
