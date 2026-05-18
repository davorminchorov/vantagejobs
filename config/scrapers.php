<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | User-Agent
    |--------------------------------------------------------------------------
    |
    | Sent on every outbound scraper request. SOURCES.md asks for an identifying
    | UA so source operators can reach out before banning us. Override in .env.
    |
    */
    'user_agent' => env('SCRAPER_USER_AGENT', 'VantageJobs/1.0 (+contact@vantagejobs.test)'),

    /*
    |--------------------------------------------------------------------------
    | Global concurrency ceiling
    |--------------------------------------------------------------------------
    |
    | Soft cap across all sources in a single `scrape:all` invocation. The
    | Saloon pool layer enforces this in addition to per-source caps.
    |
    */
    'global_concurrency' => env('SCRAPER_GLOBAL_CONCURRENCY', 30),

    /*
    |--------------------------------------------------------------------------
    | Raw HTTP response retention
    |--------------------------------------------------------------------------
    |
    | Every aggregator response body is gzipped and written to the local disk
    | by the RecordResponseArtifact middleware. The scrape-artifacts:purge
    | command sweeps any row whose retained_until has elapsed. Default 7 days
    | per PRD §11.2; override via SCRAPER_ARTIFACT_RETENTION_DAYS if you need
    | a longer or shorter window.
    |
    */
    'artifact_retention_days' => env('SCRAPER_ARTIFACT_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Per-source defaults
    |--------------------------------------------------------------------------
    |
    | concurrency  : per-source Saloon pool cap (SOURCES.md guidance).
    | timeout      : request timeout in seconds.
    | connect_timeout : TCP-connect timeout in seconds.
    | retry_times  : max retries on transient failures.
    | retry_sleep_ms : initial sleep; exponential backoff applied per attempt.
    |
    */
    'sources' => [
        'greenhouse' => [
            'concurrency' => 10,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 4,
            'retry_sleep_ms' => 1000,
        ],
        'lever' => [
            'concurrency' => 10,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 4,
            'retry_sleep_ms' => 1000,
        ],
        'ashby' => [
            'concurrency' => 10,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 4,
            'retry_sleep_ms' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregator sources (Phase 2)
    |--------------------------------------------------------------------------
    |
    | Per-source caps for the discovery-layer scrapers. Concurrency 1 across
    | the board: SOURCES.md §2.2 (Remotive ≤ 2 req/min), §2.3 (Himalayas
    | 429 on abuse), §2.4 (We Work Remotely RSS), §2.5 (RemoteOK polite-poll).
    | HackerNews is serial-monthly per §2.1.
    |
    */
    'aggregators' => [
        'hackernews' => [
            'concurrency' => 1,
            'timeout' => 30,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'remotive' => [
            'concurrency' => 1,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 2,
            'retry_sleep_ms' => 30_000,
        ],
        'himalayas' => [
            'concurrency' => 1,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'weworkremotely' => [
            'concurrency' => 1,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'remoteok' => [
            'concurrency' => 1,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
    ],

];
