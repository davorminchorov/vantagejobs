<?php

declare(strict_types=1);

namespace App\Pipeline\Offers;

use App\Models\Offer;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Computes a deterministic USD total-compensation snapshot for an offer.
 *
 * Formula (all components first normalized to USD via the static rate table):
 *   base
 *   + bonus
 *   + signing_bonus / vesting_years        (signing amortized over the grant)
 *   + annualized equity value              ((assumed_price - strike) * shares / vesting_years)
 *
 * Equity that is underwater (assumed_price <= strike) contributes zero rather
 * than a negative number. When vesting_years is null or zero, signing and
 * equity are treated as a single-year value (divided by 1) so a package with
 * no stated vesting still yields a sensible figure.
 */
final readonly class TotalCompCalculator
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function forOffer(Offer $offer): int
    {
        return $this->compute(
            currency: (string) $offer->currency,
            base: $offer->base !== null ? (int) $offer->base : null,
            bonus: $offer->bonus !== null ? (int) $offer->bonus : null,
            signingBonus: $offer->signing_bonus !== null ? (int) $offer->signing_bonus : null,
            equityShares: $offer->equity_shares !== null ? (int) $offer->equity_shares : null,
            equityStrike: $offer->equity_strike !== null ? (float) $offer->equity_strike : null,
            equityAssumedPrice: $offer->equity_assumed_price !== null ? (float) $offer->equity_assumed_price : null,
            vestingYears: $offer->vesting_years !== null ? (int) $offer->vesting_years : null,
        );
    }

    public function compute(
        string $currency,
        ?int $base,
        ?int $bonus,
        ?int $signingBonus,
        ?int $equityShares,
        ?float $equityStrike,
        ?float $equityAssumedPrice,
        ?int $vestingYears,
    ): int {
        $rate = $this->rateFor($currency);
        $years = ($vestingYears !== null && $vestingYears > 0) ? $vestingYears : 1;

        $totalInCurrency = 0.0;
        $totalInCurrency += (float) ($base ?? 0);
        $totalInCurrency += (float) ($bonus ?? 0);
        $totalInCurrency += (float) ($signingBonus ?? 0) / $years;

        if ($equityShares !== null && $equityShares > 0) {
            $strike = $equityStrike ?? 0.0;
            $assumed = $equityAssumedPrice ?? 0.0;
            $perShareGain = $assumed - $strike;

            if ($perShareGain > 0) {
                $totalInCurrency += ($perShareGain * $equityShares) / $years;
            }
        }

        return (int) round($totalInCurrency * $rate);
    }

    /**
     * Look up the USD conversion rate for a currency. Unknown currencies fall
     * back to 1.0 (treated as USD) so an unexpected code never breaks a save.
     */
    private function rateFor(string $currency): float
    {
        /** @var array<string, float> $rates */
        $rates = $this->config->get('pipeline.currency_rates_usd', []);

        return (float) ($rates[strtoupper($currency)] ?? 1.0);
    }
}
