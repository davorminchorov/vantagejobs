<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five Phase 2 aggregator sources per SOURCES.md §2.1–2.5.
 *
 * Backing values are stable identifiers used as the `type` column on
 * aggregator_sources and as the `source` column on scrape_runs when
 * source_kind = 'aggregator'.
 */
enum AggregatorSourceType: string
{
    case HACKERNEWS = 'hackernews';
    case REMOTIVE = 'remotive';
    case HIMALAYAS = 'himalayas';
    case WEWORKREMOTELY = 'weworkremotely';
    case REMOTEOK = 'remoteok';

    public function displayName(): string
    {
        return match ($this) {
            self::HACKERNEWS => 'HackerNews "Who is Hiring"',
            self::REMOTIVE => 'Remotive',
            self::HIMALAYAS => 'Himalayas',
            self::WEWORKREMOTELY => 'We Work Remotely',
            self::REMOTEOK => 'RemoteOK',
        };
    }

    /**
     * Default polling cadence per SOURCES.md guidance. HackerNews's monthly
     * thread is run on the 3rd + 5th of each month via the scheduler; the
     * cadence value here is the inter-run floor between manual triggers.
     */
    public function defaultCadenceMinutes(): int
    {
        return match ($this) {
            self::HACKERNEWS => 60 * 24 * 30,
            self::REMOTIVE => 60 * 24,
            self::HIMALAYAS => 60 * 24,
            self::WEWORKREMOTELY => 60 * 24,
            self::REMOTEOK => 60 * 24,
        };
    }
}
