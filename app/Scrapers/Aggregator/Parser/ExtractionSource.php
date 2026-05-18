<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser;

/**
 * Where an ExtractedField value came from. Used as a tie-breaker when two
 * extractors land on the same field with equal confidence, and as a hint to
 * the Phase 3 inspector for what kind of correction to surface.
 *
 *   EXPLICIT  : structured source field with a directly-usable value
 *               (Remotive.salary, Himalayas.salaryMin, RemoteOK.salary_min)
 *   INFERRED  : derived from body text or heuristics
 *               (HackerNews first-line regex, stack-tag dictionary match
 *                in body)
 *   DEFAULT   : platform default applied in the absence of any signal
 *   MISSING   : nothing extracted; placeholder so the UI can flag the gap
 */
enum ExtractionSource: string
{
    case EXPLICIT = 'explicit';
    case INFERRED = 'inferred';
    case DEFAULT = 'default';
    case MISSING = 'missing';

    /**
     * Priority for tie-breaking on equal confidence. Higher wins.
     */
    public function priority(): int
    {
        return match ($this) {
            self::EXPLICIT => 3,
            self::INFERRED => 2,
            self::DEFAULT => 1,
            self::MISSING => 0,
        };
    }
}
