<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

/**
 * Deterministic hash of `(company, normalized_title, location_bucket)`
 * per PRD §6.3.7. Two listings share the same hash when they describe
 * the same role at the same company in the same location bucket.
 *
 * Used by:
 *   - The 14-day dedup gate (incidentally — that gate already keys on
 *     the same three columns).
 *   - Boomerang detection: same hash, ≥ 14-day gap between fetches.
 *
 * The hash treats null/empty inputs as the literal string '∅' so that
 * absent slots don't collapse different roles together. We don't fall
 * back silently to substring matching — if any input is missing the
 * hash is recorded but the boomerang scanner will skip it.
 */
final readonly class RoleHasher
{
    public function hash(?string $companyKey, ?string $roleKey, ?string $locationBucket): string
    {
        $parts = [
            $companyKey === null || $companyKey === '' ? '∅' : mb_strtolower($companyKey),
            $roleKey === null || $roleKey === '' ? '∅' : mb_strtolower($roleKey),
            $locationBucket === null || $locationBucket === '' ? '∅' : mb_strtolower($locationBucket),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * True only when every input is non-empty; boomerang detection
     * shouldn't fire on hashes containing a sentinel.
     */
    public function isComplete(?string $companyKey, ?string $roleKey, ?string $locationBucket): bool
    {
        return $companyKey !== null && $companyKey !== ''
            && $roleKey !== null && $roleKey !== ''
            && $locationBucket !== null && $locationBucket !== '';
    }
}
