<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferRevision>
 */
final class OfferRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $base = fake()->numberBetween(120000, 250000);
        $bonus = fake()->numberBetween(0, 50000);

        return [
            'offer_id' => Offer::factory(),
            'snapshot' => [
                'currency' => 'USD',
                'base' => $base,
                'bonus' => $bonus,
                'signing_bonus' => 0,
                'equity_shares' => null,
                'equity_strike' => null,
                'equity_assumed_price' => null,
                'vesting_years' => null,
            ],
            'computed_total_comp_usd' => $base + $bonus,
        ];
    }
}
