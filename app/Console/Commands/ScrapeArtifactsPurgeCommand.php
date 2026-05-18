<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeRunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

/**
 * Sweeps scrape_run_artifacts whose retained_until has elapsed.
 *
 * Per PRD §6.1.6 + §11.2: raw scraper HTTP response retention is 7 days
 * (configurable via config('scrapers.artifact_retention_days')). The Saloon
 * RecordResponseArtifact middleware writes one row + one gzipped file per
 * response; this command is the matching reaper.
 *
 * Behavior:
 *   - Iterates rows where retained_until < now (no tenant scope — the table
 *     is global so the sweep sees orphan rows too).
 *   - Deletes the file from the configured disk (local). A missing file is
 *     logged as an error in the response payload but the row still drops.
 *   - Emits a single JSON line on stdout: {deleted, freed_bytes, errors}.
 *     Matches the bookmarks:expire style so the scheduler log stays grep-able.
 */
final class ScrapeArtifactsPurgeCommand extends Command
{
    protected $signature = 'scrape-artifacts:purge';

    protected $description = 'Delete scrape_run_artifacts rows + files past their retained_until.';

    public function __construct(private readonly FilesystemFactory $filesystem)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $disk = $this->filesystem->disk('local');

        $deleted = 0;
        $freedBytes = 0;
        $errors = [];

        ScrapeRunArtifact::query()
            ->where('retained_until', '<', $now)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted, &$freedBytes, &$errors, $disk): void {
                foreach ($rows as $row) {
                    try {
                        if ($disk->exists($row->path)) {
                            $disk->delete($row->path);
                        } else {
                            $errors[] = [
                                'id' => (int) $row->id,
                                'path' => $row->path,
                                'message' => 'file missing at sweep time',
                            ];
                        }
                    } catch (Throwable $e) {
                        $errors[] = [
                            'id' => (int) $row->id,
                            'path' => $row->path,
                            'message' => $e->getMessage(),
                        ];

                        // Don't drop the row when filesystem delete genuinely
                        // failed — leave it for the next sweep to retry.
                        continue;
                    }

                    $freedBytes += (int) $row->size_bytes;
                    $row->delete();
                    $deleted++;
                }
            });

        $this->line((string) json_encode([
            'command' => 'scrape-artifacts:purge',
            'deleted' => $deleted,
            'freed_bytes' => $freedBytes,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
