<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backups\BackupAlertRecipient;
use App\Backups\BackupFailedException;
use App\Backups\BackupManager;
use App\Mail\BackupFailedMail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Mailer;
use Throwable;

/**
 * Nightly off-platform database backup. Runs pg_dump (custom format) and
 * stores the archive on the configured S3-compatible disk under a timestamped
 * daily key.
 *
 * Not tenant-scoped: a single dump captures every tenant's rows. On failure it
 * queues a BackupFailedMail to the operator and exits non-zero so the
 * scheduler logs the failure.
 *
 * Emits a single JSON line on stdout, matching the bookmarks:expire /
 * exports:prune style so the scheduler log stays grep-able.
 */
final class BackupRunCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Run a pg_dump of the database and store it off-platform on the backup disk.';

    public function __construct(
        private readonly BackupManager $backups,
        private readonly BackupAlertRecipient $recipient,
        private readonly Mailer $mailer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');

        try {
            $result = $this->backups->run($now);
        } catch (Throwable $e) {
            $reason = $e instanceof BackupFailedException
                ? $e->getMessage()
                : 'Unexpected error: '.$e->getMessage();

            $this->notifyFailure($reason);

            $this->line((string) json_encode([
                'command' => 'backup:run',
                'status' => 'failed',
                'error' => $reason,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'command' => 'backup:run',
            'status' => 'ok',
            'key' => $result['key'],
            'bytes' => $result['bytes'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function notifyFailure(string $reason): void
    {
        $to = $this->recipient->resolve();
        if (! is_string($to) || $to === '') {
            return;
        }

        $this->mailer->to($to)->queue(new BackupFailedMail(
            operation: 'run',
            reason: $reason,
        ));
    }
}
