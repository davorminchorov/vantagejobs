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
 * "Corp", "Co", "Corporation", "Company", "The"), then strips all
 * non-alphanumerics. "Acme Corp" / "ACME, Inc." / "Acme Co" all collapse to
 * "acme". The original spelling is preserved in parsed_company_name.
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

        $lower = strtolower($trimmed);
        $stripped = (string) preg_replace(
            '/\b(?:incorporated|incorporation|inc|llc|ltd|limited|gmbh|corp|corporation|company|co|the)\b/i',
            '',
            $lower,
        );
        $compact = (string) preg_replace('/[^a-z0-9]/', '', $stripped);

        return $compact === '' ? null : $compact;
    }
}
