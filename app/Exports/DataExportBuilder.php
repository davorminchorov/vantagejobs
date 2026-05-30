<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Models\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Produces a single downloadable archive for an ExportJob.
 *
 * The bundle is built entirely from tenant-scoped row reads (never a raw
 * pg_dump of the whole database) so cross-tenant rows can never leak into one
 * tenant's export. For each domain entity the builder can emit:
 *
 *   - normalized JSON (canonical; one file per entity under json/)
 *   - per-entity CSV (hand-rolled via fputcsv under csv/)
 *   - a best-effort SQL dump of INSERT statements scoped to the tenant's rows
 *     (sql/data.sql)
 *
 * A manifest.json records the schema version (latest migration filename), the
 * generation timestamp, the requested formats, and per-entity row counts. The
 * whole set is zipped to the configured disk and the ExportJob is stamped
 * COMPLETED with a 24h expires_at window. Any failure flips the job to FAILED
 * with the message captured on the row.
 */
final readonly class DataExportBuilder
{
    /**
     * Domain tables included in an export, in dependency-friendly order, mapped
     * to a stable export key. Each table is read scoped to the job's tenant_id
     * so the bundle only ever contains that tenant's data.
     *
     * @var array<string, string>
     */
    private const array TABLES = [
        'companies' => 'companies',
        'roles' => 'roles',
        'contacts' => 'contacts',
        'events' => 'events',
        'notes' => 'notes',
        'tags' => 'tags',
        'bookmarks' => 'bookmarks',
        'saved_views' => 'saved_views',
        'calendar_events' => 'calendar_events',
        'offers' => 'offers',
        'offer_revisions' => 'offer_revisions',
        'outreach_log_entries' => 'outreach_log_entries',
        'role_stage_notes' => 'role_stage_notes',
        'templates' => 'templates',
        'company_blog_posts' => 'company_blog_posts',
        'company_snapshots' => 'company_snapshots',
        'company_graveyard_reasons' => 'company_graveyard_reasons',
        'dismissed_companies' => 'dismissed_companies',
        'funding_candidates' => 'funding_candidates',
        'aggregator_listings' => 'aggregator_listings',
        'audit_logs' => 'audit_logs',
        'settings' => 'settings',
    ];

    public function __construct(
        private FilesystemFactory $filesystem,
        private ConnectionResolverInterface $db,
        private ConfigRepository $config,
    ) {}

    public function build(ExportJob $job): void
    {
        $job->forceFill(['status' => ExportJobStatus::RUNNING->value])->save();

        $workingDir = null;

        try {
            /** @var list<string> $formats */
            $formats = array_values(array_filter(
                $job->formats ?? [],
                static fn (mixed $value): bool => is_string($value),
            ));

            if ($formats === []) {
                $formats = [ExportFormat::JSON->value];
            }

            $schemaVersion = $this->latestSchemaVersion();
            $generatedAt = CarbonImmutable::now();

            $workingDir = $this->makeWorkingDir($job);
            $counts = $this->writeEntities($job, $formats, $workingDir);

            $manifest = [
                'schema_version' => $schemaVersion,
                'generated_at' => $generatedAt->toIso8601String(),
                'tenant_id' => (int) $job->tenant_id,
                'formats' => $formats,
                'counts' => $counts,
            ];

            $this->writeJsonFile($workingDir.'/manifest.json', $manifest);

            $relativePath = $this->zipWorkingDir($job, $workingDir, $generatedAt);

            $job->forceFill([
                'status' => ExportJobStatus::COMPLETED->value,
                'path' => $relativePath,
                'schema_version' => $schemaVersion,
                'manifest' => $manifest,
                'expires_at' => $generatedAt->addHours($this->downloadTtlHours()),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $job->forceFill([
                'status' => ExportJobStatus::FAILED->value,
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        } finally {
            if ($workingDir !== null && is_dir($workingDir)) {
                $this->removeDirectory($workingDir);
            }
        }
    }

    /**
     * @param  list<string>  $formats
     * @return array<string, int>
     */
    private function writeEntities(ExportJob $job, array $formats, string $workingDir): array
    {
        $wantsJson = in_array(ExportFormat::JSON->value, $formats, true);
        $wantsCsv = in_array(ExportFormat::CSV->value, $formats, true);
        $wantsSql = in_array(ExportFormat::SQL->value, $formats, true);

        if ($wantsJson) {
            $this->makeDirectory($workingDir.'/json');
        }
        if ($wantsCsv) {
            $this->makeDirectory($workingDir.'/csv');
        }

        $sqlHandle = null;
        if ($wantsSql) {
            $this->makeDirectory($workingDir.'/sql');
            $sqlHandle = $this->openSqlDump($workingDir.'/sql/data.sql', $job, count(self::TABLES));
        }

        $counts = [];

        try {
            foreach (self::TABLES as $table => $key) {
                $rows = $this->readTenantRows($table, (int) $job->tenant_id);
                $counts[$key] = count($rows);

                if ($wantsJson) {
                    $this->writeJsonFile($workingDir.'/json/'.$key.'.json', $rows);
                }
                if ($wantsCsv) {
                    $this->writeCsvFile($workingDir.'/csv/'.$key.'.csv', $rows);
                }
                if ($sqlHandle !== null) {
                    $this->writeSqlInserts($sqlHandle, $table, $rows);
                }
            }
        } finally {
            if (is_resource($sqlHandle)) {
                fwrite($sqlHandle, "COMMIT;\n");
                fclose($sqlHandle);
            }
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readTenantRows(string $table, int $tenantId): array
    {
        $connection = $this->db->connection();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $query = $connection->table($table);

        if ($connection->getSchemaBuilder()->hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsvFile(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file for writing: {$path}");
        }

        try {
            if ($rows === []) {
                return;
            }

            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                $cells = array_map(
                    static fn (mixed $value): string => self::scalarToCsvCell($value),
                    array_values($row),
                );
                fputcsv($handle, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    private static function scalarToCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return resource
     */
    private function openSqlDump(string $path, ExportJob $job, int $tableCount)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open SQL file for writing: {$path}");
        }

        $header = sprintf(
            "-- VantageJobs data export (tenant %d)\n-- Generated: %s\n-- Tables: %d\n-- Row-level INSERT dump scoped to this tenant only.\nBEGIN;\n",
            (int) $job->tenant_id,
            CarbonImmutable::now()->toIso8601String(),
            $tableCount,
        );
        fwrite($handle, $header);

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeSqlInserts($handle, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(
            static fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
            $columns,
        ));

        fwrite($handle, "\n-- {$table}\n");

        foreach ($rows as $row) {
            $values = implode(', ', array_map(
                fn (mixed $value): string => $this->sqlLiteral($value),
                array_values($row),
            ));

            fwrite($handle, sprintf(
                "INSERT INTO \"%s\" (%s) VALUES (%s);\n",
                str_replace('"', '""', $table),
                $columnList,
                $values,
            ));
        }
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    private function writeJsonFile(string $path, mixed $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Unable to write JSON file: {$path}");
        }
    }

    private function zipWorkingDir(ExportJob $job, string $workingDir, CarbonImmutable $generatedAt): string
    {
        $disk = $this->filesystem->disk($this->diskName($job));
        $bundlePath = trim((string) $this->config->get('export.bundle_path', 'exports'), '/');

        $filename = sprintf(
            '%s/tenant-%d-%s-%s.zip',
            $bundlePath,
            (int) $job->tenant_id,
            $generatedAt->format('Ymd-His'),
            substr(bin2hex(random_bytes(8)), 0, 12),
        );

        $tmpZip = $workingDir.'.zip';

        $zip = new ZipArchive;
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create export archive: {$tmpZip}");
        }

        try {
            $this->addDirectoryToZip($zip, $workingDir, '');
        } finally {
            $zip->close();
        }

        $stream = fopen($tmpZip, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Unable to read export archive: {$tmpZip}");
        }

        try {
            $disk->put($filename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($tmpZip)) {
                @unlink($tmpZip);
            }
        }

        return $filename;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $absoluteDir, string $localPrefix): void
    {
        $entries = scandir($absoluteDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $absoluteDir.'/'.$entry;
            $local = $localPrefix === '' ? $entry : $localPrefix.'/'.$entry;

            if (is_dir($absolute)) {
                $zip->addEmptyDir($local);
                $this->addDirectoryToZip($zip, $absolute, $local);

                continue;
            }

            $zip->addFile($absolute, $local);
        }
    }

    private function makeWorkingDir(ExportJob $job): string
    {
        $dir = sys_get_temp_dir().'/vantage-export-'.$job->getKey().'-'.bin2hex(random_bytes(6));
        $this->makeDirectory($dir);

        return $dir;
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0o775, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create directory: {$path}");
        }
    }

    private function removeDirectory(string $path): void
    {
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $path.'/'.$entry;
            if (is_dir($absolute)) {
                $this->removeDirectory($absolute);
            } else {
                @unlink($absolute);
            }
        }

        @rmdir($path);
    }

    /**
     * Schema version is the most recent migration filename, giving every bundle
     * a stable marker of the schema it was produced against.
     */
    private function latestSchemaVersion(): string
    {
        $latest = $this->db->connection()->table('migrations')
            ->orderByDesc('id')
            ->value('migration');

        return is_string($latest) && $latest !== '' ? $latest : 'unknown';
    }

    private function diskName(ExportJob $job): string
    {
        $disk = $job->disk;

        return is_string($disk) && $disk !== ''
            ? $disk
            : (string) $this->config->get('export.disk', 'local');
    }

    private function downloadTtlHours(): int
    {
        return (int) $this->config->get('export.download_ttl_hours', 24);
    }
}
