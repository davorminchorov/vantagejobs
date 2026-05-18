<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Extracts canonical stack tags using dictionaries/stack_tags.php.
 *
 * Two input channels are merged into one field:
 *   1. structured.tags  — pre-tagged by the source (Remotive, RemoteOK).
 *      Resolved via the alias map and trusted: EXPLICIT, no context check.
 *   2. body + title text — scanned against each entry's pattern, with the
 *      entry's `context` cues required for ambiguous short tokens to pass
 *      (Go, R, C, D — see docblock on the dictionary file).
 *
 * Confidence is a one-shot judgment on the whole tag set, not per tag:
 *   - all from structured: 0.95 EXPLICIT
 *   - mixed structured + body: 0.85 EXPLICIT
 *   - body-only: 0.6 INFERRED
 *   - nothing: MISSING
 */
final class StackTagsExtractor implements Extractor
{
    public const FIELD = 'stack_tags';

    /** @var array<string, array<string, mixed>> */
    private array $dictionary;

    /** @var array<string, string>|null lowercase alias -> canonical name */
    private ?array $aliasMap = null;

    /**
     * @param  array<string, array<string, mixed>>|null  $dictionary  override for tests; defaults
     *                                                                to dictionaries/stack_tags.php
     */
    public function __construct(?array $dictionary = null)
    {
        $this->dictionary = $dictionary ?? (require base_path('dictionaries/stack_tags.php'));
    }

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $haystack = $this->buildHaystack($ctx);

        /** @var array<string, true> $explicit */
        $explicit = [];
        /** @var array<string, true> $body */
        $body = [];

        foreach ($this->extractStructuredTags($ctx) as $raw) {
            $canonical = $this->resolveCanonical($raw);
            if ($canonical !== null) {
                $explicit[$canonical] = true;
            }
        }

        if ($haystack !== '') {
            foreach ($this->dictionary as $canonical => $spec) {
                if (isset($explicit[$canonical])) {
                    continue;
                }
                if ($this->bodyMatches($canonical, $spec, $haystack)) {
                    $body[$canonical] = true;
                }
            }
        }

        if ($explicit === [] && $body === []) {
            return ExtractedField::missing(self::FIELD);
        }

        $tags = array_keys($explicit + $body);
        sort($tags);

        [$confidence, $source] = $this->scoreSet(count($explicit) > 0, count($body) > 0);

        return new ExtractedField(
            field: self::FIELD,
            value: $tags,
            confidence: $confidence,
            source: $source,
            evidence: 'structured:'.count($explicit).' body:'.count($body),
        );
    }

    private function buildHaystack(SourceContext $ctx): string
    {
        $title = (string) ($ctx->structured['title'] ?? '');
        $body = $ctx->bodyText ?? '';

        return strtolower(trim($title."\n".$body));
    }

    /**
     * @return list<string>
     */
    private function extractStructuredTags(SourceContext $ctx): array
    {
        $tags = $ctx->structured['tags'] ?? null;
        if (! is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $out[] = $tag;
            }
        }

        return $out;
    }

    private function resolveCanonical(string $rawTag): ?string
    {
        $this->aliasMap ??= $this->buildAliasMap();

        return $this->aliasMap[strtolower(trim($rawTag))] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function buildAliasMap(): array
    {
        $map = [];
        foreach ($this->dictionary as $canonical => $spec) {
            $map[strtolower($canonical)] = $canonical;
            foreach ($spec['aliases'] ?? [] as $alias) {
                $map[strtolower((string) $alias)] = $canonical;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function bodyMatches(string $canonical, array $spec, string $haystack): bool
    {
        /** @var string $pattern */
        $pattern = $spec['requires'] ?? $this->defaultPattern($canonical, $this->aliases($spec));

        if (! preg_match($pattern, $haystack)) {
            return false;
        }

        if (isset($spec['context']) && is_array($spec['context'])) {
            foreach ($spec['context'] as $cue) {
                if (! is_string($cue) || $cue === '') {
                    continue;
                }
                if (str_contains($haystack, strtolower($cue))) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function aliases(array $spec): array
    {
        $raw = $spec['aliases'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $alias) {
            if (is_string($alias) && $alias !== '') {
                $out[] = $alias;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $aliases
     */
    private function defaultPattern(string $canonical, array $aliases): string
    {
        $parts = array_merge([$canonical], $aliases);
        $escaped = array_map(static fn (string $p): string => preg_quote($p, '/'), $parts);

        return '/\b(?:'.implode('|', $escaped).')\b/i';
    }

    /**
     * @return array{0: float, 1: ExtractionSource}
     */
    private function scoreSet(bool $hasExplicit, bool $hasBody): array
    {
        if ($hasExplicit && $hasBody) {
            return [0.85, ExtractionSource::EXPLICIT];
        }
        if ($hasExplicit) {
            return [0.95, ExtractionSource::EXPLICIT];
        }

        return [0.6, ExtractionSource::INFERRED];
    }
}
