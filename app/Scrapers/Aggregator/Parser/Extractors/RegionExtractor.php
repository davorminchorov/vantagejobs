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
     * Major-city/country → region dictionary used when free-text RULES
     * don't match. Sources like Arbeitnow ship just a city ("Berlin",
     * "Munich") or a bare country name ("Germany", "Brazil") with no
     * broader region hint; this gives /discover a coarse bucket anyway.
     *
     * Lowercased keys; matched case-insensitively against the full
     * structured-field haystack with word-boundary checks. Longer keys are
     * tried first (see matchCity()) so multi-word entries such as "porto
     * alegre" win over a shorter key like "porto" that would otherwise
     * match first by declaration order.
     *
     * Deliberately excludes single ambiguous tokens shared with a US
     * state, a common name, or another place (e.g. "georgia", "jordan",
     * "victoria", "cambridge") — a false match is worse than a missed one.
     *
     * @var array<string, string>
     */
    private const CITY_REGIONS = [
        // DACH
        'berlin' => 'emea', 'munich' => 'emea', 'münchen' => 'emea', 'hamburg' => 'emea',
        'frankfurt' => 'emea', 'cologne' => 'emea', 'köln' => 'emea', 'düsseldorf' => 'emea',
        'dusseldorf' => 'emea', 'stuttgart' => 'emea', 'dresden' => 'emea', 'leipzig' => 'emea',
        'hannover' => 'emea', 'hanover' => 'emea', 'nuremberg' => 'emea', 'nürnberg' => 'emea',
        'bremen' => 'emea', 'essen' => 'emea', 'dortmund' => 'emea', 'bonn' => 'emea',
        'karlsruhe' => 'emea', 'mannheim' => 'emea', 'wiesbaden' => 'emea', 'augsburg' => 'emea',
        'aachen' => 'emea', 'cottbus' => 'emea', 'mülheim' => 'emea', 'muelheim' => 'emea',
        'hallbergmoos' => 'emea', 'pliening' => 'emea', 'osnabrück' => 'emea',
        'osnabrueck' => 'emea', 'gescher' => 'emea', 'oldenburg' => 'emea', 'langen' => 'emea',
        'greifswald' => 'emea', 'ulm' => 'emea', 'freiberg' => 'emea', 'rosenheim' => 'emea',
        'kempten' => 'emea', 'allgäu' => 'emea', 'allgau' => 'emea', 'germany' => 'emea',
        'deutschland' => 'emea',
        'vienna' => 'emea', 'wien' => 'emea', 'graz' => 'emea', 'linz' => 'emea', 'salzburg' => 'emea',
        'austria' => 'emea',
        'zurich' => 'emea', 'zürich' => 'emea', 'basel' => 'emea', 'geneva' => 'emea', 'bern' => 'emea',
        'switzerland' => 'emea',
        // Western & Northern Europe
        'london' => 'emea', 'manchester' => 'emea', 'edinburgh' => 'emea', 'dublin' => 'emea',
        'leeds' => 'emea', 'west sussex' => 'emea', 'united kingdom' => 'emea', 'uk' => 'emea',
        'ireland' => 'emea',
        'paris' => 'emea', 'lyon' => 'emea', 'marseille' => 'emea', 'toulouse' => 'emea',
        'france' => 'emea',
        'amsterdam' => 'emea', 'rotterdam' => 'emea', 'utrecht' => 'emea', 'the hague' => 'emea',
        'netherlands' => 'emea',
        'brussels' => 'emea', 'antwerp' => 'emea', 'luxembourg' => 'emea', 'belgium' => 'emea',
        'stockholm' => 'emea', 'gothenburg' => 'emea', 'malmö' => 'emea', 'malmo' => 'emea',
        'sweden' => 'emea',
        'copenhagen' => 'emea', 'aarhus' => 'emea', 'denmark' => 'emea',
        'oslo' => 'emea', 'bergen' => 'emea', 'norway' => 'emea',
        'helsinki' => 'emea', 'tampere' => 'emea', 'finland' => 'emea',
        // Southern Europe
        'madrid' => 'emea', 'barcelona' => 'emea', 'valencia' => 'emea', 'seville' => 'emea',
        'zaragoza' => 'emea', 'santa cruz de tenerife' => 'emea', 'spain' => 'emea',
        'lisbon' => 'emea', 'porto' => 'emea', 'portugal' => 'emea',
        'rome' => 'emea', 'roma' => 'emea', 'milan' => 'emea', 'turin' => 'emea',
        'naples' => 'emea', 'italy' => 'emea', 'italia' => 'emea',
        'athens' => 'emea', 'greece' => 'emea',
        // Central & Eastern Europe
        'warsaw' => 'emea', 'warszawa' => 'emea', 'krakow' => 'emea', 'kraków' => 'emea',
        'wrocław' => 'emea', 'wroclaw' => 'emea', 'gdansk' => 'emea', 'poznań' => 'emea',
        'poznan' => 'emea', 'poland' => 'emea', 'polska' => 'emea',
        'prague' => 'emea', 'brno' => 'emea', 'czech republic' => 'emea', 'czechia' => 'emea',
        'bratislava' => 'emea', 'slovakia' => 'emea',
        'budapest' => 'emea', 'hungary' => 'emea',
        'bucharest' => 'emea', 'romania' => 'emea',
        'sofia' => 'emea', 'bulgaria' => 'emea',
        'belgrade' => 'emea', 'serbia' => 'emea',
        'zagreb' => 'emea', 'croatia' => 'emea',
        'ljubljana' => 'emea', 'slovenia' => 'emea',
        'tallinn' => 'emea', 'estonia' => 'emea',
        'riga' => 'emea', 'latvia' => 'emea',
        'vilnius' => 'emea', 'lithuania' => 'emea',
        'bosnia and herzegovina' => 'emea',
        'kyiv' => 'emea', 'kiev' => 'emea', 'lviv' => 'emea', 'kharkiv' => 'emea',
        'odesa' => 'emea', 'odessa' => 'emea', 'ukraine' => 'emea',
        'minsk' => 'emea', 'belarus' => 'emea',
        // Middle East / Africa (still emea)
        'tel aviv' => 'emea', 'israel' => 'emea',
        'dubai' => 'emea', 'saudi arabia' => 'emea',
        'cairo' => 'emea', 'egypt' => 'emea',
        'istanbul' => 'emea', 'turkey' => 'emea',
        'cape town' => 'emea', 'johannesburg' => 'emea', 'south africa' => 'emea',
        'nigeria' => 'emea', 'kenya' => 'emea', 'morocco' => 'emea',
        // US hubs
        'san francisco' => 'us', 'new york' => 'us', 'nyc' => 'us', 'los angeles' => 'us',
        'seattle' => 'us', 'boston' => 'us', 'austin' => 'us', 'chicago' => 'us',
        'denver' => 'us', 'atlanta' => 'us', 'washington' => 'us', 'miami' => 'us',
        'portland' => 'us', 'minneapolis' => 'us', 'dallas' => 'us', 'houston' => 'us',
        'philadelphia' => 'us', 'phoenix' => 'us', 'san diego' => 'us', 'san jose' => 'us',
        'palo alto' => 'us', 'mountain view' => 'us', 'cupertino' => 'us', 'sunnyvale' => 'us',
        'oakland' => 'us', 'remote, usa' => 'us', 'remote us' => 'us',
        'salt lake city' => 'us', 'columbus' => 'us', 'jacksonville' => 'us',
        'texas' => 'us', 'indiana' => 'us', 'oklahoma' => 'us', 'north dakota' => 'us',
        'north carolina' => 'us', 'virginia' => 'us', 'maryland' => 'us',
        // Canada (americas — not US-only)
        'toronto' => 'americas', 'vancouver' => 'americas', 'montreal' => 'americas',
        'ottawa' => 'americas', 'calgary' => 'americas', 'edmonton' => 'americas',
        'red deer' => 'americas', 'regina' => 'americas', 'winnipeg' => 'americas',
        'kitchener' => 'americas', 'thunder bay' => 'americas', 'canada' => 'americas',
        // LATAM
        'são paulo' => 'americas', 'sao paulo' => 'americas', 'rio de janeiro' => 'americas',
        'belo horizonte' => 'americas', 'florianópolis' => 'americas', 'florianopolis' => 'americas',
        'recife' => 'americas', 'curitiba' => 'americas', 'porto alegre' => 'americas',
        'brasília' => 'americas', 'brasilia' => 'americas', 'campinas' => 'americas',
        'brazil' => 'americas', 'brasil' => 'americas',
        'mexico city' => 'americas', 'guadalajara' => 'americas', 'monterrey' => 'americas',
        'mexico' => 'americas', 'méxico' => 'americas',
        'buenos aires' => 'americas', 'mendoza' => 'americas', 'argentina' => 'americas',
        'bogotá' => 'americas', 'bogota' => 'americas', 'medellin' => 'americas', 'colombia' => 'americas',
        'lima' => 'americas', 'peru' => 'americas', 'perú' => 'americas',
        'santiago' => 'americas', 'chile' => 'americas',
        'montevideo' => 'americas', 'quito' => 'americas', 'panama city' => 'americas',
        'nassau' => 'americas', 'jamaica' => 'americas',
        // APAC
        'tokyo' => 'apac', 'osaka' => 'apac', 'kyoto' => 'apac', 'japan' => 'apac',
        'seoul' => 'apac', 'south korea' => 'apac',
        'singapore' => 'apac', 'hong kong' => 'apac',
        'shanghai' => 'apac', 'beijing' => 'apac', 'shenzhen' => 'apac', 'china' => 'apac',
        'bangalore' => 'apac', 'bengaluru' => 'apac', 'mumbai' => 'apac',
        'delhi' => 'apac', 'hyderabad' => 'apac', 'chennai' => 'apac', 'pune' => 'apac',
        'kharagpur' => 'apac', 'roorkee' => 'apac', 'jabalpur' => 'apac', 'rajkot' => 'apac',
        'krishnagiri' => 'apac', 'anupgarh' => 'apac', 'narmada' => 'apac',
        'wayanad' => 'apac', 'thiruvananthapuram' => 'apac', 'india' => 'apac',
        'sydney' => 'apac', 'melbourne' => 'apac', 'brisbane' => 'apac', 'perth' => 'apac',
        'adelaide' => 'apac', 'coffs harbour' => 'apac', 'queensland' => 'apac',
        'new zealand' => 'apac', 'auckland' => 'apac', 'wellington' => 'apac',
        'metro manila' => 'apac', 'pampanga' => 'apac', 'philippines' => 'apac',
        'jakarta' => 'apac', 'indonesia' => 'apac',
        'kuala lumpur' => 'apac', 'malaysia' => 'apac',
        'ho chi minh city' => 'apac', 'hanoi' => 'apac', 'vietnam' => 'apac',
        'taipei' => 'apac', 'taiwan' => 'apac',
        'colombo' => 'apac', 'sri lanka' => 'apac',
        'dhaka' => 'apac', 'bangladesh' => 'apac',
        'karachi' => 'apac', 'lahore' => 'apac', 'islamabad' => 'apac', 'pakistan' => 'apac',
        'kathmandu' => 'apac', 'nepal' => 'apac',
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

        // Longest key first: "porto alegre" (americas) must be tried before
        // "porto" (emea) — both pass the word-boundary check against "porto
        // alegre", since "porto" is immediately followed by a space, not a
        // letter. Declaration order alone would let the shorter, unrelated
        // key win.
        foreach ($this->cityRegionsByKeyLengthDesc() as $city => $region) {
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

    /**
     * @return array<string, string>
     */
    private function cityRegionsByKeyLengthDesc(): array
    {
        $keys = array_keys(self::CITY_REGIONS);
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = self::CITY_REGIONS[$key];
        }

        return $sorted;
    }
}
