<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prunes scrape_runs rows past their retention window.
 *
 * scrape_runs has no sweep today: ScrapeRunsReapCommand only flips stuck
 * 'running' rows to 'error', it never deletes anything, so the table grows
 * unbounded as long as scraping keeps running. Retention is
 * config('scrapers.run_retention_days') (env SCRAPER_RUN_RETENTION_DAYS,
 * default 90) measured from started_at.
 *
 * scrape_run_artifacts.scrape_run_id is nullOnDelete, so deleting a run row
 * here just nulls the artifact's back-reference rather than orphaning or
 * cascading — the shorter 7-day artifact retention (scrape-artifacts:purge)
 * means artifacts for a pruned run have almost always already been purged
 * by the time this sweep reaches it.
 *
 * No tenant scope — the table is global, like ScrapeRunsReapCommand.
 * Emits a single JSON line on stdout: {pruned}.
 */
final class ScrapeRunsPruneCommand extends Command
{
    protected $signature = 'scrape-runs:prune';

    protected $description = 'Delete scrape_runs rows older than the configured retention window.';

    public function handle(): int
    {
        $retentionDays = (int) config('scrapers.run_retention_days');
        $cutoff = CarbonImmutable::now()->subDays($retentionDays);

        $pruned = 0;

        ScrapeRun::withoutTenantScope()
            ->where('started_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$pruned): void {
                foreach ($rows as $row) {
                    $row->delete();
                    $pruned++;
                }
            });

        $this->line((string) json_encode([
            'command' => 'scrape-runs:prune',
            'pruned' => $pruned,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
