<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Bucketed region: worldwide, us, emea, apac, americas.
 *
 * Derived from the same source fields LocationExtractor reads. Used for
 * coarse filtering in /discover (the user, in CET, wants emea/worldwide
 * roles surfaced first).
 */
final class RegionExtractor implements Extractor
{
    public const FIELD = 'region';

    /**
     * Order matters — worldwide must beat region-specific terms (Remotive
     * sometimes says "Worldwide (US time zone preferred)" — we keep it
     * worldwide), and US must beat americas because "Americas" is wider.
     *
     * @var list<array{value: string, pattern: string}>
     */
    private const RULES = [
        ['value' => 'worldwide', 'pattern' => '/\b(worldwide|anywhere(?:\s+in\s+the\s+world)?)\b/i'],
        ['value' => 'emea', 'pattern' => '/\b(emea|europe(?:an)?(?:\s+only)?|eu(?:\s+only)?|cet)\b/i'],
        ['value' => 'us', 'pattern' => '/\b(usa?(?:\s+only)?|united\s+states|north\s+america|us[\s\-]based)\b/i'],
        ['value' => 'apac', 'pattern' => '/\b(apac|asia(?:[\s\-]pacific)?|australia)\b/i'],
        ['value' => 'americas', 'pattern' => '/\b(americas|latam|latin\s+america)\b/i'],
    ];

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $haystack = $this->haystack($ctx);
        if ($haystack === '') {
            return ExtractedField::missing(self::FIELD);
        }

        foreach (self::RULES as $rule) {
            if (preg_match($rule['pattern'], $haystack, $m) === 1) {
                return new ExtractedField(
                    field: self::FIELD,
                    value: $rule['value'],
                    confidence: 0.7,
                    source: ExtractionSource::INFERRED,
                    evidence: 'matched: '.$m[0],
                );
            }
        }

        return ExtractedField::missing(self::FIELD);
    }

    private function haystack(SourceContext $ctx): string
    {
        $parts = [];

        $restrictions = $ctx->structured['locationRestrictions'] ?? null;
        if (is_array($restrictions)) {
            foreach ($restrictions as $v) {
                if (is_string($v)) {
                    $parts[] = $v;
                }
            }
        }

        foreach (['candidate_required_location', 'location', 'region'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (is_string($raw)) {
                $parts[] = $raw;
            }
        }

        return trim(implode("\n", $parts));
    }
}
