<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\Company;

/**
 * Auto-link rule: when an AggregatorListing's parsed company name matches
 * an existing tracked Company with a Jaro-Winkler score ≥ 0.85 on
 * aggressively-normalized keys, return that Company so the persister can
 * set matched_company_id, flip status to `linked`, and emit a NEW_ROLE
 * Event.
 *
 * Threshold is hard-coded at 0.85 per PRD §11.2 Resolved Decisions. If the
 * false-positive / false-negative rate becomes a problem we promote it to
 * a database-backed Setting; until then, code is the source of truth.
 *
 * Match strategy (in order):
 *   1. Exact match on normalized key — cheap and covers 95% of cases
 *      ("Acme Corp" / "Acme, Inc." / "Acme Co" all collapse to "acme").
 *   2. Fuzzy fallback — scan every active Company in tenant scope, compute
 *      Jaro-Winkler against the normalized listing key, return the highest
 *      scorer ≥ 0.85. Skipped entirely when the normalized listing key is
 *      shorter than MIN_FUZZY_KEY_LENGTH: short keys ("ai", "co") clear 0.85
 *      against unrelated short company names far too easily, so only an
 *      exact match is trusted at that length. At < 500 companies the
 *      per-listing table scan is fine; if it ever bites, denormalize the
 *      key onto companies + index it.
 *
 * Tenant scoping happens implicitly via BelongsToTenant on Company.
 * Graveyarded companies (is_active = false) are excluded from candidates —
 * auto-linking a fresh aggregator sighting to a company we've stopped
 * tracking would just resurrect it silently.
 *
 * The candidate list is memoized on the instance after the first match()
 * call. This class has no explicit container binding, so it inherits
 * whatever lifetime its resolution chain gives it — in practice the
 * AggregatorListingPersister that injects it is built once per scoped
 * AggregatorRegistry resolution (see AggregatorServiceProvider), i.e. once
 * per scrape run, so the memoized list is naturally scoped to that run and
 * never needs an explicit reset.
 */
final class MatchCompanyAction
{
    public const THRESHOLD = 0.85;

    /**
     * Normalized listing keys shorter than this skip the fuzzy path
     * entirely — only an exact key match is trusted.
     */
    private const MIN_FUZZY_KEY_LENGTH = 5;

    /**
     * @var list<Company>|null
     */
    private ?array $candidates = null;

    public function __construct(private readonly CompanyKeyNormalizer $normalizer) {}

    public function match(?string $listingCompanyName): ?Company
    {
        $listingKey = $this->normalizer->normalize($listingCompanyName);
        if ($listingKey === null) {
            return null;
        }

        $allowFuzzy = mb_strlen($listingKey) >= self::MIN_FUZZY_KEY_LENGTH;

        $best = null;
        $bestScore = 0.0;

        foreach ($this->candidates() as $company) {
            $candidateKey = $this->normalizer->normalize($company->name);
            if ($candidateKey === null) {
                continue;
            }

            if ($candidateKey === $listingKey) {
                return $company;
            }

            if (! $allowFuzzy) {
                continue;
            }

            $score = $this->jaroWinkler($listingKey, $candidateKey);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $company;
            }
        }

        return $bestScore >= self::THRESHOLD ? $best : null;
    }

    /**
     * @return list<Company>
     */
    private function candidates(): array
    {
        if ($this->candidates === null) {
            $this->candidates = Company::query()->where('is_active', true)->get(['id', 'name'])->all();
        }

        return $this->candidates;
    }

    /**
     * Jaro-Winkler similarity in [0.0, 1.0]. Higher = more similar.
     *
     * Standard implementation: Jaro distance + Winkler prefix bonus (up to 4
     * leading common characters, p = 0.1). Strings are assumed already
     * lowercased and stripped of non-alphanumerics (the CompanyKeyNormalizer
     * does this upstream).
     */
    public function jaroWinkler(string $s1, string $s2): float
    {
        if ($s1 === $s2) {
            return 1.0;
        }
        if ($s1 === '' || $s2 === '') {
            return 0.0;
        }

        $len1 = strlen($s1);
        $len2 = strlen($s2);
        $matchDistance = max(0, (int) floor(max($len1, $len2) / 2) - 1);

        $s1Matches = array_fill(0, $len1, false);
        $s2Matches = array_fill(0, $len2, false);
        $matches = 0;

        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $len2);
            for ($j = $start; $j < $end; $j++) {
                if ($s2Matches[$j]) {
                    continue;
                }
                if ($s1[$i] !== $s2[$j]) {
                    continue;
                }
                $s1Matches[$i] = true;
                $s2Matches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if (! $s1Matches[$i]) {
                continue;
            }
            while (! $s2Matches[$k]) {
                $k++;
            }
            if ($s1[$i] !== $s2[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $transpositions = intdiv($transpositions, 2);

        $jaro = (
            $matches / $len1
            + $matches / $len2
            + ($matches - $transpositions) / $matches
        ) / 3;

        $prefix = 0;
        $minLen = min($len1, $len2, 4);
        for ($i = 0; $i < $minLen; $i++) {
            if ($s1[$i] !== $s2[$i]) {
                break;
            }
            $prefix++;
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }
}
