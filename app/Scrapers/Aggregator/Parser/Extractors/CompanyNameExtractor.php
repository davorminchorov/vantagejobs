<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Pulls the company name from whichever structured key the source uses.
 *
 *   Remotive  : structured.company_name
 *   Himalayas : structured.companyName
 *   RemoteOK  : structured.company
 *   We Work Remotely / HackerNews : scraper pre-splits and sets
 *                                   structured.company_name
 *
 * Trims, but preserves original casing — the normalizer in step 12 will
 * lowercase + strip suffixes for the dedup key, not here.
 */
final class CompanyNameExtractor implements Extractor
{
    public const FIELD = 'company_name';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        foreach (['company_name', 'companyName', 'company'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (! is_string($raw)) {
                continue;
            }
            $name = trim($raw);
            if ($name === '') {
                continue;
            }

            return new ExtractedField(
                field: self::FIELD,
                value: $name,
                confidence: 0.95,
                source: ExtractionSource::EXPLICIT,
                evidence: "structured.{$key}",
            );
        }

        return ExtractedField::missing(self::FIELD);
    }
}
