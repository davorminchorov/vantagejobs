<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backup disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk pg_dump archives are written to. S3-compatible by
    | default (S3/B2) so backups live off-box.
    |
    */
    'disk' => env('BACKUP_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | pg_dump
    |--------------------------------------------------------------------------
    |
    | path    : absolute path to the pg_dump binary inside the container.
    | options : extra flags passed to pg_dump. Custom format keeps the dump
    |   compressed and restorable with pg_restore.
    |
    */
    'pg_dump' => [
        'path' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
        'options' => env('BACKUP_PG_DUMP_OPTIONS', '--format=custom --no-owner --no-privileges'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention tiering
    |--------------------------------------------------------------------------
    |
    | daily_days   : keep daily backups this many days.
    | weekly_weeks : keep weekly backups this many weeks.
    | monthly      : null = keep monthly backups indefinitely.
    |
    */
    'retention' => [
        'daily_days' => (int) env('BACKUP_RETENTION_DAILY_DAYS', 30),
        'weekly_weeks' => (int) env('BACKUP_RETENTION_WEEKLY_WEEKS', 26),
        'monthly' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monthly restore verification
    |--------------------------------------------------------------------------
    |
    | When true, a monthly job downloads the latest backup and runs
    | `pg_restore --list` to verify the archive is readable.
    |
    */
    'verify_monthly' => (bool) env('BACKUP_VERIFY_MONTHLY', true),

];
