<?php

declare(strict_types=1);

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Str;

/**
 * Off-platform Postgres backup orchestration.
 *
 * Backups are whole-database pg_dump archives written to an S3-compatible
 * disk (config('backup.disk')), so they live off the application box. They
 * are NOT tenant-scoped — a single dump captures every tenant's rows.
 *
 * The manager owns three responsibilities, each driven by an artisan command:
 *
 *  - run()    : pg_dump (custom format) → temp file → Storage put at a
 *               timestamped daily key (backups/daily/YYYY-MM-DD-His.dump).
 *  - prune()  : enforce tiering — keep every daily inside the daily window,
 *               one backup per ISO week inside the weekly window, and one
 *               backup per calendar month forever. Delete the rest.
 *  - verify() : download the latest archive and run `pg_restore --list`,
 *               asserting a non-empty table-of-contents.
 *
 * pg_dump / pg_restore connection settings come from the active pgsql
 * connection in config('database.connections'); credentials are passed via
 * the PGPASSWORD environment variable so they never appear in argv.
 */
final readonly class BackupManager
{
    public const string DAILY_PREFIX = 'backups/daily';

    public function __construct(
        private ConfigRepository $config,
        private FilesystemFactory $filesystem,
        private ProcessFactory $process,
    ) {}

    /**
     * Produce a nightly dump and store it under the daily prefix.
     *
     * @return array{key: string, bytes: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $key = self::DAILY_PREFIX.'/'.$now->format('Y-m-d-His').'.dump';
        $tempPath = $this->tempPath($now);

        try {
            $this->dumpTo($tempPath);

            $contents = (string) file_get_contents($tempPath);
            $this->disk()->put($key, $contents);

            return ['key' => $key, 'bytes' => strlen($contents)];
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Enforce retention tiering across stored daily backups.
     *
     * Tiers (config('backup.retention')):
     *  - daily_days   : keep every backup whose date is within this window.
     *  - weekly_weeks : outside the daily window but inside the weekly window,
     *                   keep the newest backup per ISO week.
     *  - monthly      : null = keep the newest backup per calendar month
     *                   forever (older than the weekly window).
     *
     * Everything not retained by a tier is deleted.
     *
     * @return array{kept: list<string>, deleted: list<string>}
     */
    public function prune(CarbonImmutable $now): array
    {
        $retention = (array) $this->config->get('backup.retention');
        $dailyDays = (int) ($retention['daily_days'] ?? 30);
        $weeklyWeeks = (int) ($retention['weekly_weeks'] ?? 26);

        $dailyCutoff = $now->subDays($dailyDays)->startOfDay();
        $weeklyCutoff = $now->subWeeks($weeklyWeeks)->startOfDay();

        $keep = [];
        $weeklySeen = [];
        $monthlySeen = [];

        // Newest first so the first backup encountered for a given week/month
        // is the one we retain.
        $backups = $this->backups();
        usort($backups, static fn (array $a, array $b): int => $b['taken_at'] <=> $a['taken_at']);

        foreach ($backups as $backup) {
            /** @var CarbonImmutable $takenAt */
            $takenAt = $backup['taken_at'];
            $key = $backup['key'];

            if ($takenAt->greaterThanOrEqualTo($dailyCutoff)) {
                $keep[$key] = true;

                continue;
            }

            if ($takenAt->greaterThanOrEqualTo($weeklyCutoff)) {
                $weekBucket = $takenAt->format('o-W');
                if (! isset($weeklySeen[$weekBucket])) {
                    $weeklySeen[$weekBucket] = true;
                    $keep[$key] = true;
                }

                continue;
            }

            $monthBucket = $takenAt->format('Y-m');
            if (! isset($monthlySeen[$monthBucket])) {
                $monthlySeen[$monthBucket] = true;
                $keep[$key] = true;
            }
        }

        $kept = [];
        $deleted = [];

        foreach ($backups as $backup) {
            $key = $backup['key'];
            if (isset($keep[$key])) {
                $kept[] = $key;

                continue;
            }

            $this->disk()->delete($key);
            $deleted[] = $key;
        }

        sort($kept);
        sort($deleted);

        return ['kept' => $kept, 'deleted' => $deleted];
    }

    /**
     * Download the most recent backup and run `pg_restore --list`, returning
     * the parsed result. A non-empty table-of-contents means the archive is
     * readable and restorable.
     *
     * @return array{key: string, toc_entries: int, readable: bool, error: ?string}|null
     *                                                                                    Null when no backup exists to verify.
     */
    public function verify(): ?array
    {
        $latest = $this->latestKey();
        if ($latest === null) {
            return null;
        }

        $tempPath = $this->tempPath(CarbonImmutable::now(), 'verify');

        try {
            file_put_contents($tempPath, (string) $this->disk()->get($latest));

            $result = $this->process->run([
                $this->pgRestorePath(),
                '--list',
                $tempPath,
            ]);

            if (! $result->successful()) {
                return [
                    'key' => $latest,
                    'toc_entries' => 0,
                    'readable' => false,
                    'error' => trim($result->errorOutput()) ?: 'pg_restore --list exited non-zero',
                ];
            }

            $tocEntries = $this->countTocEntries($result->output());

            return [
                'key' => $latest,
                'toc_entries' => $tocEntries,
                'readable' => $tocEntries > 0,
                'error' => $tocEntries > 0 ? null : 'pg_restore --list returned an empty table of contents',
            ];
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * The newest stored backup key, or null when none exist.
     */
    public function latestKey(): ?string
    {
        $backups = $this->backups();
        if ($backups === []) {
            return null;
        }

        usort($backups, static fn (array $a, array $b): int => $b['taken_at'] <=> $a['taken_at']);

        return $backups[0]['key'];
    }

    /**
     * Run pg_dump for the configured pgsql connection into $target.
     */
    private function dumpTo(string $target): void
    {
        $connection = (array) $this->config->get('database.connections.pgsql');
        $pgDump = (array) $this->config->get('backup.pg_dump');

        $command = [$this->stringConfig($pgDump, 'path', 'pg_dump')];

        foreach ($this->splitOptions($this->stringConfig($pgDump, 'options', '')) as $option) {
            $command[] = $option;
        }

        $command[] = '--host='.$this->stringConfig($connection, 'host', '127.0.0.1');
        $command[] = '--port='.$this->stringConfig($connection, 'port', '5432');
        $command[] = '--username='.$this->stringConfig($connection, 'username', 'root');
        $command[] = '--file='.$target;
        $command[] = $this->stringConfig($connection, 'database', 'laravel');

        $result = $this->process
            ->env(['PGPASSWORD' => $this->stringConfig($connection, 'password', '')])
            ->run($command);

        if (! $result->successful()) {
            throw new BackupFailedException(
                'pg_dump failed: '.(trim($result->errorOutput()) ?: 'unknown error'),
            );
        }
    }

    /**
     * List every stored backup under the daily prefix with its parsed
     * timestamp.
     *
     * @return list<array{key: string, taken_at: CarbonImmutable}>
     */
    private function backups(): array
    {
        $backups = [];

        foreach ($this->disk()->files(self::DAILY_PREFIX) as $key) {
            $takenAt = $this->parseTakenAt($key);
            if ($takenAt === null) {
                continue;
            }

            $backups[] = ['key' => $key, 'taken_at' => $takenAt];
        }

        return $backups;
    }

    /**
     * Parse the YYYY-MM-DD-His timestamp out of a backup key.
     */
    private function parseTakenAt(string $key): ?CarbonImmutable
    {
        $basename = Str::of($key)->afterLast('/')->before('.dump')->value();

        $parsed = CarbonImmutable::createFromFormat('Y-m-d-His', $basename, 'UTC');

        return $parsed === false ? null : $parsed;
    }

    /**
     * Count non-comment entries in `pg_restore --list` output. The TOC header
     * lines begin with ';' and are skipped; every other non-blank line is an
     * archive entry.
     */
    private function countTocEntries(string $output): int
    {
        $count = 0;

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, ';')) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function pgRestorePath(): string
    {
        $configured = $this->config->get('backup.pg_restore.path');

        return is_string($configured) && $configured !== '' ? $configured : 'pg_restore';
    }

    private function tempPath(CarbonImmutable $now, string $tag = 'run'): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/vantagejobs-backup-'.$tag.'-'
            .$now->format('Ymd-His').'-'.Str::random(8).'.dump';
    }

    private function disk(): Filesystem
    {
        return $this->filesystem->disk((string) $this->config->get('backup.disk', 's3'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function stringConfig(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return list<string>
     */
    private function splitOptions(string $options): array
    {
        $trimmed = trim($options);
        if ($trimmed === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', $trimmed) ?: [],
            static fn (string $part): bool => $part !== '',
        ));
    }
}
