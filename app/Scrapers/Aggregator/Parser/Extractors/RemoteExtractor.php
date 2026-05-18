<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Compound `remote` field. Value shape:
 *   {
 *     type: 'remote' | 'hybrid' | 'on_site' | 'unknown',
 *     timezone_restrictions: list<string>,   // e.g. ["UTC+01:00", "UTC+02:00"]
 *     geo_restrictions:      list<string>,   // e.g. ["EMEA"], ["US"]
 *   }
 *
 * Timezone restrictions come almost exclusively from Himalayas' structured
 * timezoneRestrictions[] (per SOURCES.md §2.3 — the headline feature for a
 * CET-based user). They can also be parsed from body text but the patterns
 * are noisy enough to ship in Phase 3 corrections, not here.
 */
final class RemoteExtractor implements Extractor
{
    public const FIELD = 'remote';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $type = $this->detectType($ctx);
        $timezones = $this->extractTimezones($ctx);
        $geo = $this->extractGeo($ctx);

        if ($type === 'unknown' && $timezones === [] && $geo === []) {
            return ExtractedField::missing(self::FIELD);
        }

        // Himalayas with timezone data is the high-confidence path. Otherwise
        // we're inferring from keywords / location strings.
        $explicitTimezones = is_array($ctx->structured['timezoneRestrictions'] ?? null)
            && $ctx->structured['timezoneRestrictions'] !== [];

        if ($explicitTimezones) {
            $confidence = 0.9;
            $source = ExtractionSource::EXPLICIT;
        } elseif ($type !== 'unknown') {
            $confidence = 0.7;
            $source = ExtractionSource::INFERRED;
        } else {
            $confidence = 0.5;
            $source = ExtractionSource::INFERRED;
        }

        return new ExtractedField(
            field: self::FIELD,
            value: [
                'type' => $type,
                'timezone_restrictions' => $timezones,
                'geo_restrictions' => $geo,
            ],
            confidence: $confidence,
            source: $source,
            evidence: "type:{$type} tz:".count($timezones).' geo:'.count($geo),
        );
    }

    /**
     * @return 'remote'|'hybrid'|'on_site'|'unknown'
     */
    private function detectType(SourceContext $ctx): string
    {
        $haystack = strtolower(implode("\n", [
            (string) ($ctx->structured['title'] ?? ''),
            (string) ($ctx->structured['position'] ?? ''),
            (string) ($ctx->structured['candidate_required_location'] ?? ''),
            (string) ($ctx->structured['location'] ?? ''),
            (string) ($ctx->structured['region'] ?? ''),
            $ctx->bodyText ?? '',
        ]));

        // Pre-parsed boolean signals (some sources expose this directly).
        $isRemoteFlag = $ctx->structured['is_remote'] ?? null;
        if ($isRemoteFlag === true) {
            return 'remote';
        }

        if (preg_match('/\bhybrid\b/i', $haystack) === 1) {
            return 'hybrid';
        }

        if (preg_match('/\b(remote|wfh|work\s+from\s+home|distributed|fully\s+remote|anywhere|worldwide)\b/i', $haystack) === 1) {
            return 'remote';
        }

        if (preg_match('/\b(on[\s\-]site|in[\s\-]office|office[\s\-]based)\b/i', $haystack) === 1) {
            return 'on_site';
        }

        return 'unknown';
    }

    /**
     * @return list<string>
     */
    private function extractTimezones(SourceContext $ctx): array
    {
        $structured = $ctx->structured['timezoneRestrictions'] ?? null;
        if (! is_array($structured)) {
            return [];
        }

        $out = [];
        foreach ($structured as $tz) {
            if (is_string($tz) && trim($tz) !== '') {
                $out[] = trim($tz);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractGeo(SourceContext $ctx): array
    {
        /** @var list<string> $out */
        $out = [];

        $restrictions = $ctx->structured['locationRestrictions'] ?? null;
        if (is_array($restrictions)) {
            foreach ($restrictions as $r) {
                if (is_string($r)) {
                    $normalized = $this->normalizeGeo($r);
                    if ($normalized !== null) {
                        $out[] = $normalized;
                    }
                }
            }
        }

        foreach (['candidate_required_location', 'location', 'region'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $normalized = $this->normalizeGeo($raw);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeGeo(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        return match (true) {
            preg_match('/\b(worldwide|anywhere)\b/', $value) === 1 => null,
            preg_match('/\b(emea|europe|eu\s+only|cet)\b/', $value) === 1 => 'EMEA',
            preg_match('/\b(usa?|united\s+states|north\s+america|us[\s\-]based)\b/', $value) === 1 => 'US',
            preg_match('/\b(apac|asia|australia)\b/', $value) === 1 => 'APAC',
            preg_match('/\b(americas|latam|latin\s+america)\b/', $value) === 1 => 'Americas',
            default => null,
        };
    }
}
