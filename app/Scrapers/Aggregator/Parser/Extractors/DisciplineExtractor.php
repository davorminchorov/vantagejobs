<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Discipline is inferred from the role title (and falls back to the body).
 *
 * Canonical values: backend, frontend, fullstack, mobile, devops, data, ml,
 * security, qa, design, engineering (catch-all). Returns MISSING if no
 * keyword fires.
 */
final class DisciplineExtractor implements Extractor
{
    public const FIELD = 'discipline';

    /**
     * Ordered by specificity — fullstack must beat backend/frontend, mobile
     * must beat fullstack on iOS/Android titles, etc.
     *
     * @var list<array{value: string, pattern: string}>
     */
    private const RULES = [
        ['value' => 'mobile', 'pattern' => '/\b(ios|android|mobile)\s+(engineer|developer|dev)\b/i'],
        ['value' => 'fullstack', 'pattern' => '/\b(full[\s\-]?stack)\b/i'],
        ['value' => 'frontend', 'pattern' => '/\b(front[\s\-]?end|frontend|client[\s\-]?side|ui\s+engineer)\b/i'],
        ['value' => 'backend', 'pattern' => '/\b(back[\s\-]?end|backend|server[\s\-]?side|api\s+engineer)\b/i'],
        ['value' => 'devops', 'pattern' => '/\b(devops|sre|site\s+reliability|platform\s+engineer|infrastructure\s+engineer)\b/i'],
        ['value' => 'data', 'pattern' => '/\b(data\s+engineer|analytics\s+engineer|data\s+scientist|data\s+analyst)\b/i'],
        ['value' => 'ml', 'pattern' => '/\b(ml\s+engineer|machine\s+learning|ai\s+engineer|research\s+(?:engineer|scientist))\b/i'],
        ['value' => 'security', 'pattern' => '/\b(security\s+engineer|appsec|application\s+security|infosec)\b/i'],
        ['value' => 'qa', 'pattern' => '/\b(qa\s+engineer|quality\s+assurance|test\s+engineer|sdet)\b/i'],
        ['value' => 'design', 'pattern' => '/\b(product\s+designer|ux\s+designer|ui\s+designer|design\s+engineer)\b/i'],
    ];

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $title = (string) ($ctx->structured['title'] ?? $ctx->structured['position'] ?? '');
        $haystack = trim($title."\n".($ctx->bodyText ?? ''));

        if ($haystack === '') {
            return ExtractedField::missing(self::FIELD);
        }

        foreach (self::RULES as $rule) {
            if (preg_match($rule['pattern'], $haystack, $m) === 1) {
                return new ExtractedField(
                    field: self::FIELD,
                    value: $rule['value'],
                    confidence: 0.8,
                    source: ExtractionSource::INFERRED,
                    evidence: 'matched: '.$m[0],
                );
            }
        }

        // Generic "Engineer" as a last-ditch tag, low confidence.
        if (preg_match('/\bengineer(?:ing)?\b/i', $haystack) === 1) {
            return new ExtractedField(
                field: self::FIELD,
                value: 'engineering',
                confidence: 0.3,
                source: ExtractionSource::INFERRED,
                evidence: 'generic engineer fallback',
            );
        }

        return ExtractedField::missing(self::FIELD);
    }
}
