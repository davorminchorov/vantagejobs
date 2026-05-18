<?php

declare(strict_types=1);

namespace App\Signals\Layoffs;

use Carbon\CarbonImmutable;

/**
 * Parsed row from the layoffs.fyi / Kaggle mirror dataset.
 *
 * `normalizedKey` is the output of CompanyKeyNormalizer applied to
 * `companyName`. It's what we join against companies on.
 *
 * `source` is the upstream provider ('layoffs_fyi' for the Kaggle mirror,
 * 'trueup' for the TrueUp tracker per SOURCES §4.2). Stored on the Event
 * body so we can tell signals apart later.
 */
final readonly class LayoffsRecord
{
    public function __construct(
        public string $companyName,
        public string $normalizedKey,
        public ?int $headcount,
        public ?float $percent,
        public CarbonImmutable $date,
        public ?string $industry,
        public ?string $country,
        public ?string $stage,
        public string $source,
    ) {}
}
