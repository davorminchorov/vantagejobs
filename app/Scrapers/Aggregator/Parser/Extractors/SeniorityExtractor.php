<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Canonical seniority bucket. Prefers Himalayas' structured.seniority[] when
 * present; otherwise inferred from the title.
 *
 * Values: intern, junior, mid, senior, staff, principal, lead, director.
 */
final class SeniorityExtractor implements Extractor
{
    public const FIELD = 'seniority';

    /**
     * Order matters — director must beat principal-mention, principal must
     * beat senior, lead must come before senior because "tech lead" titles
     * also contain "senior" sometimes.
     *
     * @var list<array{value: string, pattern: string}>
     */
    private const RULES = [
        ['value' => 'intern', 'pattern' => '/\b(intern(?:ship)?)\b/i'],
        ['value' => 'director', 'pattern' => '/\b(director|vp\b|vice\s+president|head\s+of)\b/i'],
        ['value' => 'principal', 'pattern' => '/\b(principal)\b/i'],
        ['value' => 'staff', 'pattern' => '/\b(staff)\b/i'],
        ['value' => 'lead', 'pattern' => '/\b(tech\s+lead|engineering\s+lead|team\s+lead|lead\s+engineer)\b/i'],
        ['value' => 'senior', 'pattern' => '/\b(senior|sr\.?)\b/i'],
        ['value' => 'mid', 'pattern' => '/\b(mid[\s\-]?level|mid[\s\-]engineer)\b/i'],
        ['value' => 'junior', 'pattern' => '/\b(junior|jr\.?)\b/i'],
    ];

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $structured = $ctx->structured['seniority'] ?? null;
        if (is_array($structured) && isset($structured[0]) && is_string($structured[0]) && $structured[0] !== '') {
            return new ExtractedField(
                field: self::FIELD,
                value: $this->normalize($structured[0]),
                confidence: 0.9,
                source: ExtractionSource::EXPLICIT,
                evidence: 'structured.seniority[0]',
            );
        }
        if (is_string($structured) && trim($structured) !== '') {
            return new ExtractedField(
                field: self::FIELD,
                value: $this->normalize($structured),
                confidence: 0.9,
                source: ExtractionSource::EXPLICIT,
                evidence: 'structured.seniority',
            );
        }

        $title = (string) ($ctx->structured['title'] ?? $ctx->structured['position'] ?? '');
        if ($title === '') {
            return ExtractedField::missing(self::FIELD);
        }

        foreach (self::RULES as $rule) {
            if (preg_match($rule['pattern'], $title, $m) === 1) {
                return new ExtractedField(
                    field: self::FIELD,
                    value: $rule['value'],
                    confidence: 0.8,
                    source: ExtractionSource::INFERRED,
                    evidence: 'title: '.$m[0],
                );
            }
        }

        return ExtractedField::missing(self::FIELD);
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
