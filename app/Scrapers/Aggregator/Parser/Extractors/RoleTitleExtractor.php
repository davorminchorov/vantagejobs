<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 *   Remotive / Himalayas / WWR : structured.title
 *   RemoteOK                   : structured.position
 *   HN                         : scraper pre-splits first line and sets
 *                                structured.title
 */
final class RoleTitleExtractor implements Extractor
{
    public const FIELD = 'role_title';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        foreach (['title', 'position'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (! is_string($raw)) {
                continue;
            }
            $title = trim($raw);
            if ($title === '') {
                continue;
            }

            return new ExtractedField(
                field: self::FIELD,
                value: $title,
                confidence: 0.95,
                source: ExtractionSource::EXPLICIT,
                evidence: "structured.{$key}",
            );
        }

        return ExtractedField::missing(self::FIELD);
    }
}
