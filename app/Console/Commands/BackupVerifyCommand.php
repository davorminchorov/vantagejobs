<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backups\BackupAlertRecipient;
use App\Backups\BackupManager;
use App\Mail\BackupFailedMail;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Monthly restore verification. Downloads the latest stored backup and runs
 * `pg_restore --list`, asserting a non-empty table of contents so we know the
 * archive is readable and restorable.
 *
 * Skips silently when config('backup.verify_monthly') is false. On a failed or
 * empty TOC it queues a BackupFailedMail and exits non-zero. When there is no
 * backup to verify yet the command reports that and succeeds (nothing to
 * check is not an error).
 *
 * Emits a single JSON line on stdout.
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify';

    protected $description = 'Download the latest backup and verify it with pg_restore --list.';

    public function __construct(
        private readonly BackupManager $backups,
        private readonly BackupAlertRecipient $recipient,
        private readonly Mailer $mailer,
        private readonly ConfigRepository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) $this->config->get('backup.verify_monthly', true)) {
            $this->line((string) json_encode([
                'command' => 'backup:verify',
                'status' => 'skipped',
                'reason' => 'verify_monthly disabled',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $result = $this->backups->verify();

        if ($result === null) {
            $this->line((string) json_encode([
                'command' => 'backup:verify',
                'status' => 'no_backup',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (! $result['readable']) {
            $reason = $result['error'] ?? 'Backup archive is unreadable.';
            $this->notifyFailure($reason, $result['key']);

            $this->line((string) json_encode([
                'command' => 'backup:verify',
                'status' => 'failed',
                'key' => $result['key'],
                'error' => $reason,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'command' => 'backup:verify',
            'status' => 'ok',
            'key' => $result['key'],
            'toc_entries' => $result['toc_entries'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function notifyFailure(string $reason, string $backupKey): void
    {
        $to = $this->recipient->resolve();
        if (! is_string($to) || $to === '') {
            return;
        }

        $this->mailer->to($to)->queue(new BackupFailedMail(
            operation: 'verify',
            reason: $reason,
            backupKey: $backupKey,
        ));
    }
}
