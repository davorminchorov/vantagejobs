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
    | scrape_runs retention
    |--------------------------------------------------------------------------
    |
    | scrape_runs has no bound otherwise: it grows one row per company per
    | scheduled sweep forever. The scrape-runs:prune command deletes rows
    | whose started_at is older than this window. Override via
    | SCRAPER_RUN_RETENTION_DAYS if you need longer history for analysis.
    |
    */
    'run_retention_days' => env('SCRAPER_RUN_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Aggregator-born role staleness
    |--------------------------------------------------------------------------
    |
    | Roles created from an auto-linked aggregator listing (source like
    | 'aggregator:%') have no ATS to confirm they've closed — nothing re-
    | fetches them. The roles:close-stale-aggregator sweep closes any that
    | haven't been re-seen (last_seen_at) within this many days. Override via
    | SCRAPER_AGGREGATOR_ROLE_STALE_DAYS.
    |
    */
    'aggregator_role_stale_days' => env('SCRAPER_AGGREGATOR_ROLE_STALE_DAYS', 21),

    /*
    |--------------------------------------------------------------------------
    | Per-source defaults
    |--------------------------------------------------------------------------
    |
    | timeout      : request timeout in seconds.
    | connect_timeout : TCP-connect timeout in seconds.
    | retry_times  : max retries on transient failures.
    | retry_sleep_ms : initial sleep; exponential backoff applied per attempt.
    |
    */
    'sources' => [
        'greenhouse' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 4,
            'retry_sleep_ms' => 1000,
        ],
        'lever' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 4,
            'retry_sleep_ms' => 1000,
        ],
        'ashby' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 4,
            'retry_sleep_ms' => 1000,
        ],
        'workable' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'smartrecruiters' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'personio' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'recruitee' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'teamtailor' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'pinpoint' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'bamboohr' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
        'rippling' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregator sources (Phase 2 + optional Phase 3)
    |--------------------------------------------------------------------------
    |
    | Per-source settings for the discovery-layer scrapers. Each runs one
    | request (or a small serial sequence) per invocation — see SOURCES.md
    | §2.2 (Remotive ≤ 2 req/min), §2.3 (Himalayas 429 on abuse), §2.4 (We
    | Work Remotely RSS), §2.5 (RemoteOK polite-poll). HackerNews is
    | serial-monthly per §2.1. 4dayweek, Working Nomads, and Adzuna
    | (deferred / optional, SOURCES.md §3.6-3.8) follow the same
    | polite-poll pattern.
    |
    */
    'aggregators' => [
        'hackernews' => [
            'timeout' => 30,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'remotive' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 2,
            'retry_sleep_ms' => 30_000,
        ],
        'himalayas' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'weworkremotely' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'remoteok' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'fourdayweek' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'workingnomads' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'larajobs' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'adzuna' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
            // Credentials register at https://developer.adzuna.com/
            'app_id' => env('ADZUNA_APP_ID'),
            'app_key' => env('ADZUNA_APP_KEY'),
            'countries' => env('ADZUNA_COUNTRIES', 'gb,de,nl,fr,at,ch,pl'),
            'pages_per_country' => env('ADZUNA_PAGES_PER_COUNTRY', 3),
            // Free-text filter appended to the search query. Empty by default:
            // category=it-jobs already scopes results, and a hardcoded
            // `what=software` was excluding real postings (e.g. "Senior
            // Backend Engineer") that never use the literal word "software".
            'what' => env('ADZUNA_WHAT', ''),
        ],
        'jobicy' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
            // Jobicy documents a 1-req/hr poll limit; daily cadence
            // (defaultCadenceMinutes) keeps us well inside it.
            // 50 is the API's documented per-request cap - it offers no
            // pagination/cursor and no total-count field, so there is no way
            // to detect or recover truncation beyond this value.
            'count' => env('JOBICY_COUNT', 50),
        ],
        'arbeitnow' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
            // Upstream supports ?page=N; walked until links.next is null or
            // this cap is hit, whichever comes first.
            'max_pages' => env('ARBEITNOW_MAX_PAGES', 5),
        ],
        'python_org' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'django_news' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'elixir_jobs' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
        'hexhire' => [
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source health thresholds (Phase 6)
    |--------------------------------------------------------------------------
    |
    | Drives the dashboard source-health tile and the monitor:source-health
    | alert sweep.
    |
    | consecutive_failure_alert : a source at or above this many consecutive
    |   failed scheduled runs is RED and triggers a fix-me alert email.
    | confidence_drop_wow_pct   : a week-over-week drop in a source's median
    |   (p50) parse confidence larger than this percentage flips the source
    |   to RED (drop > pct) or YELLOW (drop within half the pct).
    | stale_run_hours           : a source whose last_run_at is older than this
    |   (or never ran) while enabled is YELLOW (stale), independent of the
    |   failure counter.
    |
    */
    'source_health' => [
        'consecutive_failure_alert' => (int) env('SCRAPER_SOURCE_HEALTH_FAILURE_ALERT', 3),
        'confidence_drop_wow_pct' => (int) env('SCRAPER_SOURCE_HEALTH_CONFIDENCE_DROP_WOW_PCT', 20),
        'stale_run_hours' => (int) env('SCRAPER_SOURCE_HEALTH_STALE_RUN_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | TrueUp layoffs scrape (Phase 4 fallback)
    |--------------------------------------------------------------------------
    |
    | TrueUp is the secondary signal source for layoffs when layoffs.fyi is
    | unavailable (SOURCES.md §4.2). HTML scrape, no API.
    |
    */
    'trueup' => [
        'url' => env('TRUEUP_LAYOFFS_URL', 'https://www.trueup.io/layoffs'),
        'timeout' => 30,
        'connect_timeout' => 5,
    ],

];
