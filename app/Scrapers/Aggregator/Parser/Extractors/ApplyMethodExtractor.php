<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Where to apply. Returns either a URL or "email:<address>".
 *
 *   Remotive  : structured.url
 *   Himalayas : structured.applicationLink
 *   RemoteOK  : structured.apply_url
 *   HN        : first URL or email in the body
 */
final class ApplyMethodExtractor implements Extractor
{
    public const FIELD = 'apply_method';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        foreach (['applicationLink', 'apply_url', 'url'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (! is_string($raw)) {
                continue;
            }
            $url = trim($raw);
            if ($url === '' || ! $this->looksLikeUrl($url)) {
                continue;
            }

            return new ExtractedField(
                field: self::FIELD,
                value: $url,
                confidence: 0.9,
                source: ExtractionSource::EXPLICIT,
                evidence: "structured.{$key}",
            );
        }

        if (is_string($ctx->url) && $this->looksLikeUrl($ctx->url)) {
            return new ExtractedField(
                field: self::FIELD,
                value: $ctx->url,
                confidence: 0.7,
                source: ExtractionSource::INFERRED,
                evidence: 'context.url',
            );
        }

        $body = $ctx->bodyText ?? '';
        if ($body !== '') {
            if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $body, $m) === 1) {
                return new ExtractedField(
                    field: self::FIELD,
                    value: 'email:'.$m[0],
                    confidence: 0.6,
                    source: ExtractionSource::INFERRED,
                    evidence: 'body email',
                );
            }
            if (preg_match('/https?:\/\/[^\s<>"\']+/i', $body, $m) === 1) {
                return new ExtractedField(
                    field: self::FIELD,
                    value: $m[0],
                    confidence: 0.6,
                    source: ExtractionSource::INFERRED,
                    evidence: 'body url',
                );
            }
        }

        return ExtractedField::missing(self::FIELD);
    }

    private function looksLikeUrl(string $value): bool
    {
        return (bool) preg_match('/^https?:\/\//i', $value);
    }
}
