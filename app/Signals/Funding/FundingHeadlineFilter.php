<?php

declare(strict_types=1);

namespace App\Signals\Funding;

use App\Models\FundingCandidate;
use Carbon\CarbonImmutable;

/**
 * Decides whether a news headline is worth reviewing as funding news for a
 * company. Used when scanning Google News and to re-check stored, still
 * unreviewed candidates, so both follow the same rules:
 *
 *  - the title contains a preset funding keyword (FundingCandidate::PRESET_KEYWORDS);
 *  - when "raised" is the only keyword, the headline also names an amount
 *    (not a valuation) or a round, and is not stock-market news
 *    ("price target raised", "raised outlook");
 *  - the headline, without the " - Publisher" suffix, names the company;
 *  - the publisher is not a company directory site (FundingCandidate::DIRECTORY_SITES);
 *  - it was published within FundingCandidate::MAX_AGE_MONTHS.
 */
final readonly class FundingHeadlineFilter
{
    private const MARKET_NEWS_PATTERN = '/\b(price targets?|outlook|guidance|forecasts?|dividends?|ebitda|earnings|eps|shares?|stocks?|revenue|rating)\b/i';

    public function __construct(private FundingHeadlineParser $parser = new FundingHeadlineParser) {}

    /**
     * @return list<string>
     */
    public function matchedKeywords(string $title): array
    {
        // Whole words only: "Series B" must not match "Series Based".
        $matched = [];
        foreach (FundingCandidate::PRESET_KEYWORDS as $keyword) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/iu', $title) === 1) {
                $matched[] = $keyword;
            }
        }

        return $matched;
    }

    /**
     * @param  string|null  $sourceHost  Host of the RSS <source url>, when known.
     * @param  string|null  $sourceName  Text of the RSS <source> element, when known.
     */
    public function accepts(
        string $title,
        string $companyName,
        ?CarbonImmutable $publishedAt,
        ?string $sourceHost = null,
        ?string $sourceName = null,
    ): bool {
        $matched = $this->matchedKeywords($title);
        if ($matched === []) {
            return false;
        }

        if (! $publishedAt instanceof CarbonImmutable
            || $publishedAt->lessThan(CarbonImmutable::now()->subMonths(FundingCandidate::MAX_AGE_MONTHS))) {
            return false;
        }

        $parsed = $this->parser->parse($title);

        if ($matched === ['raised'] && ! $this->looksLikeFundingRaise($parsed)) {
            return false;
        }

        if (! $this->namesCompany($parsed['headline'], $companyName)) {
            return false;
        }

        $publisher = $sourceName !== null && trim($sourceName) !== '' ? $sourceName : (string) $parsed['publisher'];

        return ! $this->isDirectorySite((string) $sourceHost, $publisher);
    }

    /**
     * @param  array{headline: string, publisher: string|null, stage: string|null, amount_usd: int|null, amount_label: string|null}  $parsed
     */
    private function looksLikeFundingRaise(array $parsed): bool
    {
        if ($parsed['stage'] === null && $parsed['amount_label'] === null) {
            return false;
        }

        return preg_match(self::MARKET_NEWS_PATTERN, $parsed['headline']) !== 1;
    }

    /**
     * Whole-word, case-insensitive match so "WEX" does not match "Wexford".
     * Trailing legal suffixes ("Acme Inc.") are ignored.
     */
    private function namesCompany(string $headline, string $companyName): bool
    {
        $name = trim((string) preg_replace('/[\s,]+(inc\.?|ltd\.?|llc|gmbh|ag|s\.?a\.?|b\.?v\.?)$/i', '', trim($companyName)));
        if ($name === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $headline) === 1;
    }

    private function isDirectorySite(string $sourceHost, string $publisher): bool
    {
        $publisherKey = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($publisher));
        $host = mb_strtolower($sourceHost);

        foreach (FundingCandidate::DIRECTORY_SITES as $site) {
            if (str_starts_with($publisherKey, $site) || preg_match('/(^|\.)'.preg_quote($site, '/').'\.[a-z.]+$/', $host) === 1) {
                return true;
            }
        }

        return false;
    }
}
