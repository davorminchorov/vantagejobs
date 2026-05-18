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

    /**
     * Major-city → region dictionary used when free-text RULES don't match.
     * Sources like Arbeitnow ship just a city ("Berlin", "Munich") with no
     * country/region hint; this gives /discover a coarse bucket anyway.
     *
     * Lowercased keys; matched case-insensitively against the full
     * structured-field haystack with word-boundary checks.
     *
     * @var array<string, string>
     */
    private const CITY_REGIONS = [
        // DACH
        'berlin' => 'emea', 'munich' => 'emea', 'münchen' => 'emea', 'hamburg' => 'emea',
        'frankfurt' => 'emea', 'cologne' => 'emea', 'köln' => 'emea', 'düsseldorf' => 'emea',
        'dusseldorf' => 'emea', 'stuttgart' => 'emea', 'dresden' => 'emea', 'leipzig' => 'emea',
        'hannover' => 'emea', 'nuremberg' => 'emea', 'nürnberg' => 'emea', 'bremen' => 'emea',
        'essen' => 'emea', 'dortmund' => 'emea', 'bonn' => 'emea', 'karlsruhe' => 'emea',
        'mannheim' => 'emea', 'wiesbaden' => 'emea', 'augsburg' => 'emea', 'aachen' => 'emea',
        'vienna' => 'emea', 'wien' => 'emea', 'graz' => 'emea', 'linz' => 'emea', 'salzburg' => 'emea',
        'zurich' => 'emea', 'zürich' => 'emea', 'basel' => 'emea', 'geneva' => 'emea', 'bern' => 'emea',
        // Western & Northern Europe
        'london' => 'emea', 'manchester' => 'emea', 'edinburgh' => 'emea', 'dublin' => 'emea',
        'paris' => 'emea', 'lyon' => 'emea', 'marseille' => 'emea', 'toulouse' => 'emea',
        'amsterdam' => 'emea', 'rotterdam' => 'emea', 'utrecht' => 'emea', 'the hague' => 'emea',
        'brussels' => 'emea', 'antwerp' => 'emea', 'luxembourg' => 'emea',
        'stockholm' => 'emea', 'gothenburg' => 'emea', 'malmö' => 'emea', 'malmo' => 'emea',
        'copenhagen' => 'emea', 'aarhus' => 'emea', 'oslo' => 'emea', 'bergen' => 'emea',
        'helsinki' => 'emea', 'tampere' => 'emea',
        // Southern Europe
        'madrid' => 'emea', 'barcelona' => 'emea', 'valencia' => 'emea', 'seville' => 'emea',
        'lisbon' => 'emea', 'porto' => 'emea', 'rome' => 'emea', 'milan' => 'emea', 'turin' => 'emea',
        'naples' => 'emea', 'athens' => 'emea',
        // Central & Eastern Europe
        'warsaw' => 'emea', 'krakow' => 'emea', 'kraków' => 'emea', 'wrocław' => 'emea',
        'wroclaw' => 'emea', 'gdansk' => 'emea', 'poznań' => 'emea', 'poznan' => 'emea',
        'prague' => 'emea', 'brno' => 'emea', 'bratislava' => 'emea', 'budapest' => 'emea',
        'bucharest' => 'emea', 'sofia' => 'emea', 'belgrade' => 'emea', 'zagreb' => 'emea',
        'ljubljana' => 'emea', 'tallinn' => 'emea', 'riga' => 'emea', 'vilnius' => 'emea',
        // Middle East / Africa (still emea)
        'tel aviv' => 'emea', 'dubai' => 'emea', 'cairo' => 'emea', 'istanbul' => 'emea',
        'cape town' => 'emea', 'johannesburg' => 'emea',
        // US hubs
        'san francisco' => 'us', 'new york' => 'us', 'nyc' => 'us', 'los angeles' => 'us',
        'seattle' => 'us', 'boston' => 'us', 'austin' => 'us', 'chicago' => 'us',
        'denver' => 'us', 'atlanta' => 'us', 'washington' => 'us', 'miami' => 'us',
        'portland' => 'us', 'minneapolis' => 'us', 'dallas' => 'us', 'houston' => 'us',
        'philadelphia' => 'us', 'phoenix' => 'us', 'san diego' => 'us', 'san jose' => 'us',
        'palo alto' => 'us', 'mountain view' => 'us', 'cupertino' => 'us', 'sunnyvale' => 'us',
        'oakland' => 'us', 'remote, usa' => 'us', 'remote us' => 'us',
        // Canada (americas — not US-only)
        'toronto' => 'americas', 'vancouver' => 'americas', 'montreal' => 'americas',
        'ottawa' => 'americas', 'calgary' => 'americas',
        // LATAM
        'são paulo' => 'americas', 'sao paulo' => 'americas', 'rio de janeiro' => 'americas',
        'mexico city' => 'americas', 'buenos aires' => 'americas', 'bogotá' => 'americas',
        'bogota' => 'americas', 'lima' => 'americas', 'santiago' => 'americas',
        // APAC
        'tokyo' => 'apac', 'osaka' => 'apac', 'kyoto' => 'apac', 'seoul' => 'apac',
        'singapore' => 'apac', 'hong kong' => 'apac', 'shanghai' => 'apac', 'beijing' => 'apac',
        'shenzhen' => 'apac', 'bangalore' => 'apac', 'bengaluru' => 'apac', 'mumbai' => 'apac',
        'delhi' => 'apac', 'hyderabad' => 'apac', 'chennai' => 'apac', 'pune' => 'apac',
        'sydney' => 'apac', 'melbourne' => 'apac', 'brisbane' => 'apac', 'perth' => 'apac',
        'auckland' => 'apac', 'wellington' => 'apac',
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

        $cityHit = $this->matchCity($haystack);
        if ($cityHit !== null) {
            return new ExtractedField(
                field: self::FIELD,
                value: $cityHit['region'],
                confidence: 0.65,
                source: ExtractionSource::INFERRED,
                evidence: 'city: '.$cityHit['name'],
            );
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

        foreach (['candidate_required_location', 'location', 'jobGeo', 'region'] as $key) {
            $raw = $ctx->structured[$key] ?? null;
            if (is_string($raw)) {
                $parts[] = $raw;
            }
        }

        // HexHire and similar emit per-item <category> elements that often
        // carry location hints alongside tech tags.
        $categories = $ctx->structured['categories'] ?? null;
        if (is_array($categories)) {
            foreach ($categories as $c) {
                if (is_string($c)) {
                    $parts[] = $c;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array{name: string, region: string}|null
     */
    private function matchCity(string $haystack): ?array
    {
        $lower = mb_strtolower($haystack);
        foreach (self::CITY_REGIONS as $city => $region) {
            // Word-boundary on either side keeps "berlin" from matching
            // inside "berliner" etc. Multi-word cities (e.g. "san francisco")
            // use the same boundary treatment.
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($city, '/').'(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $lower) === 1) {
                return ['name' => $city, 'region' => $region];
            }
        }

        return null;
    }
}
