<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExportJobStatus;
use App\Models\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

/**
 * Sweeps export bundles whose 24h download window has elapsed.
 *
 * For every COMPLETED export past expires_at the command deletes the archive
 * from its disk and flips the row to EXPIRED (the row is kept as an audit of
 * what was exported and when). The table is global, so the sweep runs without
 * a tenant scope and sees every tenant's bundles.
 *
 * Emits a single JSON line on stdout, matching the bookmarks:expire /
 * scrape-artifacts:purge style so the scheduler log stays grep-able.
 */
final class ExportsPruneCommand extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete expired export bundles from disk and mark their jobs expired.';

    public function __construct(private readonly FilesystemFactory $filesystem)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $expired = 0;
        $filesDeleted = 0;
        $errors = [];

        ExportJob::withoutTenantScope()
            ->where('status', ExportJobStatus::COMPLETED->value)
            ->where('expires_at', '<', $now)
            ->orderBy('id')
            ->chunkById(200, function ($jobs) use (&$expired, &$filesDeleted, &$errors): void {
                foreach ($jobs as $job) {
                    $expired++;

                    if (is_string($job->path) && $job->path !== '') {
                        try {
                            $disk = $this->filesystem->disk($this->diskName($job));
                            if ($disk->exists($job->path)) {
                                $disk->delete($job->path);
                                $filesDeleted++;
                            }
                        } catch (Throwable $e) {
                            $errors[] = [
                                'id' => (int) $job->getKey(),
                                'path' => $job->path,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }

                    $job->forceFill([
                        'status' => ExportJobStatus::EXPIRED->value,
                        'path' => null,
                    ])->save();
                }
            });

        $this->line((string) json_encode([
            'command' => 'exports:prune',
            'expired' => $expired,
            'files_deleted' => $filesDeleted,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function diskName(ExportJob $job): string
    {
        $disk = $job->disk;

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
