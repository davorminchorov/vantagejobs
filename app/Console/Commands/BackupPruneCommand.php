<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backups\BackupManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Enforce backup retention tiering on the backup disk: keep every daily inside
 * the daily window, one backup per ISO week inside the weekly window, and one
 * backup per calendar month forever. Everything else is deleted.
 *
 * Runs after backup:run in the nightly band so the freshest dump is already in
 * place before older copies are reaped.
 *
 * Emits a single JSON line on stdout listing kept and deleted keys.
 */
final class BackupPruneCommand extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Apply 30d-daily / 26w-weekly / monthly-indefinite retention tiering to stored backups.';

    public function __construct(private readonly BackupManager $backups)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->backups->prune(CarbonImmutable::now('UTC'));

        $this->line((string) json_encode([
            'command' => 'backup:prune',
            'kept' => count($result['kept']),
            'deleted' => count($result['deleted']),
            'deleted_keys' => $result['deleted'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
