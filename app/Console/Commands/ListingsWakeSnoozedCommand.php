<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Flips SNOOZED aggregator listings back to NEW once snoozed_until elapses,
 * per the transition documented on AggregatorListingStatus. SnoozeListingAction
 * only ever writes the snooze; nothing previously read snoozed_until back, so
 * a snoozed listing stayed hidden from /discover forever instead of the
 * "hidden for N days" behavior the triage UI implies.
 *
 * Tenant-agnostic (no --tenant option) — sweeps the aggregator_listings table
 * directly, matching scrape-artifacts:purge's global-sweep style, since this
 * has no tenant-specific business logic to branch on.
 *
 * Emits a single JSON line on stdout: {woken}.
 */
final class ListingsWakeSnoozedCommand extends Command
{
    protected $signature = 'listings:wake-snoozed';

    protected $description = 'Flip SNOOZED aggregator listings back to NEW once snoozed_until has elapsed.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $woken = 0;

        AggregatorListing::withoutTenantScope()
            ->where('status', AggregatorListingStatus::SNOOZED->value)
            ->where('snoozed_until', '<=', $now)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$woken): void {
                foreach ($rows as $row) {
                    $row->fill([
                        'status' => AggregatorListingStatus::NEW,
                        'snoozed_until' => null,
                    ])->save();
                    $woken++;
                }
            });

        $this->line((string) json_encode([
            'command' => 'listings:wake-snoozed',
            'woken' => $woken,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
