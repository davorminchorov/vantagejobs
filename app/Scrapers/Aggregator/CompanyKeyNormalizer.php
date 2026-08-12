<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

/**
 * Shared company-name normalizer used for:
 *   - aggregator_listings.normalized_company_key (set by scrapers in steps 7-11)
 *   - DismissedCompany lookup (step 13)
 *   - MatchCompany auto-link Jaro-Winkler input (step 12)
 *
 * Lowercases, strips common legal suffixes ("Inc", "LLC", "Ltd", "GmbH",
 * "Corp", "Co", "Corporation", "Company", "The"), then strips everything
 * that isn't a letter or digit (unicode-aware, so non-Latin company names
 * normalize instead of collapsing to an empty string). "Acme Corp" /
 * "ACME, Inc." / "Acme Co" all collapse to "acme". The original spelling is
 * preserved in parsed_company_name.
 *
 * Latin-only input normalizes identically to the previous ASCII-only
 * implementation, so no backfill is needed for existing Latin-script keys —
 * `signals:listings:rehash --renormalize` only needs to touch rows whose
 * source name contains non-Latin characters.
 */
final class CompanyKeyNormalizer
{
    public function normalize(?string $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        $stripped = (string) preg_replace(
            '/\b(?:incorporated|incorporation|inc|llc|ltd|limited|gmbh|corp|corporation|company|co|the)\b/iu',
            '',
            $lower,
        );
        $compact = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $stripped);

        return $compact === '' ? null : $compact;
    }
}
