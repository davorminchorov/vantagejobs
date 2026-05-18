<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Visa sponsorship signal, parsed from body text. Returns bool value or
 * MISSING. Confidence is INFERRED — visa language is rarely written with
 * the clarity we'd want.
 *
 * Negative signals are checked first because positive phrases ("sponsor
 * visas") appear inside negations ("we cannot sponsor visas"). The negative
 * regex is kept tight to unambiguous markers ("cannot sponsor", "us
 * citizens only") so noisy phrases like "must be authorized to work" don't
 * flip postings that also explicitly sponsor.
 */
final class VisaSponsorshipExtractor implements Extractor
{
    public const FIELD = 'visa_sponsorship';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $haystack = strtolower(trim(
            (string) ($ctx->structured['description'] ?? '')."\n".
            (string) ($ctx->bodyText ?? '')
        ));

        if ($haystack === '') {
            return ExtractedField::missing(self::FIELD);
        }

        if (preg_match('/\b(no\s+visa\s+sponsorship|cannot\s+sponsor|do\s+not\s+sponsor|us\s+citizens?\s+only)\b/', $haystack, $m) === 1) {
            return new ExtractedField(
                field: self::FIELD,
                value: false,
                confidence: 0.7,
                source: ExtractionSource::INFERRED,
                evidence: 'negative: '.$m[0],
            );
        }

        if (preg_match('/\b(visa\s+sponsorship|we\s+sponsor|sponsor\s+visas?|h[\-\s]?1b|tier\s+2\s+visa|skilled\s+worker\s+visa)\b/', $haystack, $m) === 1) {
            return new ExtractedField(
                field: self::FIELD,
                value: true,
                confidence: 0.7,
                source: ExtractionSource::INFERRED,
                evidence: 'positive: '.$m[0],
            );
        }

        return ExtractedField::missing(self::FIELD);
    }
}
