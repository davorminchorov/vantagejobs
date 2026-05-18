<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 *   Himalayas : structured.locationRestrictions[] — joined with ', '
 *   Remotive  : structured.candidate_required_location
 *   RemoteOK  : structured.location
 *   WWR       : structured.region (free-text)
 *
 * Confidence is lower for free-text "Worldwide" / "Anywhere" since those
 * carry no geographic precision; consumers should prefer remote.geo for
 * geographic restrictions.
 */
final class LocationExtractor implements Extractor
{
    public const FIELD = 'location';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $restrictions = $ctx->structured['locationRestrictions'] ?? null;
        if (is_array($restrictions) && $restrictions !== []) {
            $clean = array_values(array_filter(
                array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $restrictions),
                static fn (string $v): bool => $v !== '',
            ));
            if ($clean !== []) {
                return new ExtractedField(
                    field: self::FIELD,
                    value: implode(', ', $clean),
                    confidence: 0.9,
                    source: ExtractionSource::EXPLICIT,
                    evidence: 'structured.locationRestrictions',
                );
            }
        }

        foreach (['candidate_required_location', 'location', 'region'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (! is_string($raw)) {
                continue;
            }
            $value = trim($raw);
            if ($value === '') {
                continue;
            }
            $confidence = preg_match('/\b(worldwide|anywhere)\b/i', $value) === 1 ? 0.6 : 0.9;

            return new ExtractedField(
                field: self::FIELD,
                value: $value,
                confidence: $confidence,
                source: ExtractionSource::EXPLICIT,
                evidence: "structured.{$key}",
            );
        }

        return ExtractedField::missing(self::FIELD);
    }
}
