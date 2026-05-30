<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
final class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $base = fake()->numberBetween(120000, 250000);
        $bonus = fake()->numberBetween(0, 50000);

        return [
            'role_id' => Role::factory(),
            'currency' => 'USD',
            'base' => $base,
            'bonus' => $bonus,
            'signing_bonus' => fake()->numberBetween(0, 50000),
            'equity_shares' => null,
            'equity_strike' => null,
            'equity_assumed_price' => null,
            'vesting_years' => fake()->randomElement([null, 4]),
            'computed_total_comp_usd' => $base + $bonus,
            'decision_deadline' => fake()->optional()->dateTimeBetween('+1 week', '+1 month'),
            'notes' => null,
        ];
    }

    public function withEquity(): self
    {
        return $this->state(fn (): array => [
            'equity_shares' => fake()->numberBetween(1000, 50000),
            'equity_strike' => fake()->randomFloat(4, 0.5, 5),
            'equity_assumed_price' => fake()->randomFloat(4, 5, 50),
            'vesting_years' => 4,
        ]);
    }

    public function inCurrency(string $currency): self
    {
        return $this->state(fn (): array => ['currency' => $currency]);
    }
}
