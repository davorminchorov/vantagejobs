<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Discipline is inferred from the role title first.
 *
 * The description is only read when the title already names a tech role
 * ("Software Engineer" + a description about APIs is backend), or when the
 * source has no real titles (Hacker News posts, where the "title" is often
 * "Full time" or a location). Descriptions of non-tech roles routinely
 * mention engineering ("work with our engineering team"), so reading them
 * for every listing tagged recruiters and account executives as engineers.
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

    /** Words that make a title a tech role on their own. */
    private const string TECH_ROLE_PATTERN = '/\b(engineer(?:ing)?|developer|programmer|swe)\b/i';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $title = trim((string) ($ctx->structured['title'] ?? $ctx->structured['position'] ?? ''));
        $body = trim((string) ($ctx->bodyText ?? ''));

        if ($title === '' && $body === '') {
            return ExtractedField::missing(self::FIELD);
        }

        $fromTitle = self::matchRule($title);
        if ($fromTitle !== null) {
            return self::found($fromTitle[0], 0.8, 'title matched: '.$fromTitle[1]);
        }

        $techTitle = preg_match(self::TECH_ROLE_PATTERN, $title) === 1;
        $freeTextTitle = $title === '' || $ctx->sourceType === AggregatorSourceType::HACKERNEWS;
        if (! $techTitle && ! $freeTextTitle) {
            return ExtractedField::missing(self::FIELD);
        }

        $fromBody = self::matchRule($body);
        if ($fromBody !== null) {
            return self::found($fromBody[0], 0.6, 'description matched: '.$fromBody[1]);
        }

        // Generic "Engineer" as a last-ditch tag, low confidence.
        if ($techTitle || preg_match(self::TECH_ROLE_PATTERN, $body) === 1) {
            return self::found('engineering', 0.3, 'generic engineer fallback');
        }

        return ExtractedField::missing(self::FIELD);
    }

    /**
     * @return array{string, string}|null The discipline and the matched text.
     */
    private static function matchRule(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        foreach (self::RULES as $rule) {
            if (preg_match($rule['pattern'], $text, $m) === 1) {
                return [$rule['value'], $m[0]];
            }
        }

        return null;
    }

    private static function found(string $value, float $confidence, string $evidence): ExtractedField
    {
        return new ExtractedField(
            field: self::FIELD,
            value: $value,
            confidence: $confidence,
            source: ExtractionSource::INFERRED,
            evidence: $evidence,
        );
    }
}
