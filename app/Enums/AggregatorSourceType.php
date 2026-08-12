<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Aggregator sources per SOURCES.md §2 (Phase 2) and §3 (deferred /
 * optional). Backing values are stable identifiers used as the `type`
 * column on aggregator_sources and as the `source` column on scrape_runs
 * when source_kind = 'aggregator'.
 */
enum AggregatorSourceType: string
{
    case HACKERNEWS = 'hackernews';
    case REMOTIVE = 'remotive';
    case HIMALAYAS = 'himalayas';
    case WEWORKREMOTELY = 'weworkremotely';
    case REMOTEOK = 'remoteok';
    case FOURDAYWEEK = 'fourdayweek';
    case WORKINGNOMADS = 'workingnomads';
    case ADZUNA = 'adzuna';
    case LARAJOBS = 'larajobs';
    case JOBICY = 'jobicy';
    case ARBEITNOW = 'arbeitnow';
    case PYTHON_ORG = 'python_org';
    case DJANGO_NEWS = 'django_news';
    case ELIXIR_JOBS = 'elixir_jobs';
    case HEXHIRE = 'hexhire';

    public function displayName(): string
    {
        return match ($this) {
            self::HACKERNEWS => 'HackerNews "Who is Hiring"',
            self::REMOTIVE => 'Remotive',
            self::HIMALAYAS => 'Himalayas',
            self::WEWORKREMOTELY => 'We Work Remotely',
            self::REMOTEOK => 'RemoteOK',
            self::FOURDAYWEEK => '4dayweek.io',
            self::WORKINGNOMADS => 'Working Nomads',
            self::ADZUNA => 'Adzuna',
            self::LARAJOBS => 'LaraJobs',
            self::JOBICY => 'Jobicy',
            self::ARBEITNOW => 'Arbeitnow',
            self::PYTHON_ORG => 'Python.org Jobs',
            self::DJANGO_NEWS => 'Django Job Board',
            self::ELIXIR_JOBS => 'Elixir Jobs',
            self::HEXHIRE => 'HexHire',
        };
    }

    /**
     * Default polling cadence per SOURCES.md guidance. HackerNews's monthly
     * thread is run on the 3rd + 5th of each month via the scheduler; the
     * cadence value here is the inter-run floor between manual triggers.
     *
     * Daily group: sources with documented rate limits, server-side caches,
     * or ToS that make more frequent polling pointless or hostile (Remotive
     * ≤4 polls/day, Himalayas 24h cache, WWR RSS-only ToS, Adzuna metered
     * quota). Six-hourly group: permissive feeds with nothing strict
     * documented, where fresher listings help early applications.
     */
    public function defaultCadenceMinutes(): int
    {
        return match ($this) {
            self::HACKERNEWS => 60 * 24 * 30,
            self::REMOTIVE,
            self::HIMALAYAS,
            self::WEWORKREMOTELY,
            self::ADZUNA => 60 * 24,
            self::REMOTEOK,
            self::FOURDAYWEEK,
            self::WORKINGNOMADS,
            self::LARAJOBS,
            self::JOBICY,
            self::ARBEITNOW,
            self::PYTHON_ORG,
            self::DJANGO_NEWS,
            self::ELIXIR_JOBS,
            self::HEXHIRE => 60 * 6,
        };
    }
}
