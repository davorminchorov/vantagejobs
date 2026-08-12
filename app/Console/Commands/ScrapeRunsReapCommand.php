<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reaps scrape_runs rows stuck at status='running'.
 *
 * Both ScrapeOrchestrator and AggregatorOrchestrator only finalize a run from
 * inside their own try/catch — a worker that dies mid-run (OOM, a hard
 * restart, the job timeout's SIGALRM landing outside that catch) leaves the
 * row 'running' forever with nothing to notice. 30 minutes matches the scrape
 * jobs' ShouldBeUnique::$uniqueFor, so a reaped run's dispatch lock has also
 * expired by the time this sweep acts on it — no tenant scope, the table is
 * global.
 *
 * Emits a single JSON line on stdout: {reaped}. Matches the
 * scrape-artifacts:purge / exports:prune style so the scheduler log stays
 * grep-able.
 */
final class ScrapeRunsReapCommand extends Command
{
    protected $signature = 'scrape-runs:reap';

    protected $description = "Mark scrape_runs stuck at status='running' past a 30-minute deadline as errored.";

    private const STALE_AFTER_MINUTES = 30;

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES);
        $now = CarbonImmutable::now();

        $reaped = 0;

        ScrapeRun::withoutTenantScope()
            ->where('status', 'running')
            ->where('started_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$reaped, $now): void {
                foreach ($rows as $row) {
                    $row->fill([
                        'status' => 'error',
                        'finished_at' => $now,
                        'errors' => [[
                            'message' => 'reaped: run exceeded '.self::STALE_AFTER_MINUTES.' minutes, worker presumed dead',
                        ]],
                    ])->save();
                    $reaped++;
                }
            });

        $this->line((string) json_encode([
            'command' => 'scrape-runs:reap',
            'reaped' => $reaped,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
