<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Download window
    |--------------------------------------------------------------------------
    |
    | Hours an export bundle remains downloadable before the prune step marks
    | it expired and deletes the archive.
    |
    */
    'download_ttl_hours' => (int) env('EXPORT_DOWNLOAD_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk export bundles are written to.
    |
    */
    'disk' => env('EXPORT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Bundle path
    |--------------------------------------------------------------------------
    |
    | Directory (relative to the disk root) where export archives live.
    |
    */
    'bundle_path' => env('EXPORT_BUNDLE_PATH', 'exports'),

];
