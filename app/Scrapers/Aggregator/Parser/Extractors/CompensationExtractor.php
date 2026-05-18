<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Compound `compensation` field. Value shape:
 *   {
 *     min:           ?int,    // raw, in `currency`
 *     max:           ?int,
 *     currency:      ?string,  // ISO-ish: USD, EUR, GBP, CAD, AUD
 *     interval:      'annual' | 'hourly',
 *     equity:        bool,
 *     min_usd:       ?int,    // currency-normalized
 *     max_usd:       ?int,
 *   }
 *
 * Per PRD §11.2 we keep raw nominal USD-normalized values only — no cost-
 * of-living adjustments. Currency rates are hard-coded for v1; revisit if
 * an offer-comparison feature lands (it doesn't until Phase 5+).
 */
final class CompensationExtractor implements Extractor
{
    public const FIELD = 'compensation';

    /**
     * 2026 approximate spot rates for the currencies we expect to see in
     * software-eng job postings. Good enough for "is this $200k or €120k"
     * triage; not good enough for accounting.
     */
    private const USD_RATES = [
        'USD' => 1.00,
        'EUR' => 1.08,
        'GBP' => 1.27,
        'CAD' => 0.74,
        'AUD' => 0.66,
        'CHF' => 1.10,
        'SEK' => 0.095,
        'NOK' => 0.092,
        'DKK' => 0.144,
        'PLN' => 0.25,
    ];

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        if (($parsed = $this->fromHimalayas($ctx)) !== null) {
            return $this->build($parsed, 0.95, ExtractionSource::EXPLICIT, 'himalayas');
        }
        if (($parsed = $this->fromRemoteOk($ctx)) !== null) {
            return $this->build($parsed, 0.9, ExtractionSource::EXPLICIT, 'remoteok');
        }
        if (($parsed = $this->fromSalaryString($ctx)) !== null) {
            return $this->build($parsed, 0.7, ExtractionSource::INFERRED, 'salary-string');
        }
        if (($parsed = $this->fromBody($ctx)) !== null) {
            return $this->build($parsed, 0.5, ExtractionSource::INFERRED, 'body-regex');
        }

        return ExtractedField::missing(self::FIELD);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromHimalayas(SourceContext $ctx): ?array
    {
        $min = $ctx->structured['salaryMin'] ?? null;
        $max = $ctx->structured['salaryMax'] ?? null;
        $currency = $ctx->structured['salaryCurrency'] ?? null;

        if (! is_numeric($min) && ! is_numeric($max)) {
            return null;
        }

        return [
            'min' => is_numeric($min) ? (int) $min : null,
            'max' => is_numeric($max) ? (int) $max : null,
            'currency' => is_string($currency) ? strtoupper($currency) : 'USD',
            'interval' => 'annual',
            'equity' => $this->detectEquity($ctx),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromRemoteOk(SourceContext $ctx): ?array
    {
        $min = $ctx->structured['salary_min'] ?? null;
        $max = $ctx->structured['salary_max'] ?? null;

        if (! is_numeric($min) && ! is_numeric($max)) {
            return null;
        }

        return [
            'min' => is_numeric($min) ? (int) $min : null,
            'max' => is_numeric($max) ? (int) $max : null,
            'currency' => 'USD', // RemoteOK figures are USD-denominated by convention
            'interval' => 'annual',
            'equity' => $this->detectEquity($ctx),
        ];
    }

    /**
     * Remotive ships salary as a free-form string ("$150,000 - $200,000",
     * "€80k–€120k", "150k+ USD").
     *
     * @return array<string, mixed>|null
     */
    private function fromSalaryString(SourceContext $ctx): ?array
    {
        $raw = $ctx->structured['salary'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $this->parseRange($raw, $ctx);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromBody(SourceContext $ctx): ?array
    {
        $body = $ctx->bodyText ?? '';
        if ($body === '') {
            return null;
        }

        return $this->parseRange($body, $ctx);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseRange(string $text, SourceContext $ctx): ?array
    {
        $currency = $this->detectCurrency($text);
        $interval = $this->detectInterval($text);

        // Match patterns like "150k - 200k", "$150,000 - $200,000",
        // "€80k–€120k", "USD 150000 to 200000".
        $numberPattern = '(?:\$|€|£|usd|eur|gbp|cad|aud)?\s*(\d{1,3}(?:[,.\s]\d{3})*|\d+)\s*(k|K)?';
        $separator = '\s*(?:-|–|—|to)\s*';

        if (preg_match("/{$numberPattern}{$separator}{$numberPattern}/i", $text, $m) === 1) {
            $min = $this->toInt($m[1], $m[2] ?? '');
            $max = $this->toInt($m[3], $m[4] ?? '');

            return [
                'min' => $min,
                'max' => $max,
                'currency' => $currency,
                'interval' => $interval,
                'equity' => $this->detectEquity($ctx),
            ];
        }

        // Single number followed by "+" — treat as a min.
        if (preg_match("/{$numberPattern}\s*\+/i", $text, $m) === 1) {
            $min = $this->toInt($m[1], $m[2] ?? '');

            return [
                'min' => $min,
                'max' => null,
                'currency' => $currency,
                'interval' => $interval,
                'equity' => $this->detectEquity($ctx),
            ];
        }

        return null;
    }

    private function detectCurrency(string $text): string
    {
        return match (true) {
            preg_match('/€|\beur\b/i', $text) === 1 => 'EUR',
            preg_match('/£|\bgbp\b/i', $text) === 1 => 'GBP',
            preg_match('/\bcad\b/i', $text) === 1 => 'CAD',
            preg_match('/\baud\b/i', $text) === 1 => 'AUD',
            preg_match('/\bchf\b/i', $text) === 1 => 'CHF',
            preg_match('/\bpln\b/i', $text) === 1 => 'PLN',
            default => 'USD',
        };
    }

    /**
     * @return 'annual'|'hourly'
     */
    private function detectInterval(string $text): string
    {
        return preg_match('/\b(per\s+hour|\/hr|\/hour|hourly)\b/i', $text) === 1
            ? 'hourly'
            : 'annual';
    }

    private function detectEquity(SourceContext $ctx): bool
    {
        $body = (string) ($ctx->bodyText ?? '');
        $salary = (string) ($ctx->structured['salary'] ?? '');
        $description = (string) ($ctx->structured['description'] ?? '');
        $haystack = $body."\n".$salary."\n".$description;

        return preg_match('/\b(equity|stock\s+options|rsus?\b|options\s+package)\b/i', $haystack) === 1;
    }

    /**
     * @param  string  $magnitude  one of "" or "k"|"K" — multiplies by 1000.
     */
    private function toInt(string $number, string $magnitude): int
    {
        $cleaned = (int) preg_replace('/[^\d]/', '', $number);
        $multiplier = strtolower($magnitude) === 'k' ? 1000 : 1;

        return $cleaned * $multiplier;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function build(array $parsed, float $confidence, ExtractionSource $source, string $tag): ExtractedField
    {
        $currency = strtoupper((string) ($parsed['currency'] ?? 'USD'));
        $rate = self::USD_RATES[$currency] ?? null;

        $min = isset($parsed['min']) && is_int($parsed['min']) && $parsed['min'] > 0 ? $parsed['min'] : null;
        $max = isset($parsed['max']) && is_int($parsed['max']) && $parsed['max'] > 0 ? $parsed['max'] : null;

        $minUsd = ($min !== null && $rate !== null) ? (int) round($min * $rate) : null;
        $maxUsd = ($max !== null && $rate !== null) ? (int) round($max * $rate) : null;

        return new ExtractedField(
            field: self::FIELD,
            value: [
                'min' => $min,
                'max' => $max,
                'currency' => $currency,
                'interval' => $parsed['interval'] ?? 'annual',
                'equity' => (bool) ($parsed['equity'] ?? false),
                'min_usd' => $minUsd,
                'max_usd' => $maxUsd,
            ],
            confidence: $confidence,
            source: $source,
            evidence: $tag,
        );
    }
}
