<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline\Offers;

use App\Pipeline\Offers\TotalCompCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TotalCompCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function calculator(): TotalCompCalculator
    {
        return app(TotalCompCalculator::class);
    }

    #[Test]
    public function it_sums_cash_components_in_usd(): void
    {
        $total = $this->calculator()->compute(
            currency: 'USD',
            base: 180_000,
            bonus: 20_000,
            signingBonus: 40_000,
            equityShares: null,
            equityStrike: null,
            equityAssumedPrice: null,
            vestingYears: 4,
        );

        // 180k + 20k + (40k / 4) = 210k
        $this->assertSame(210_000, $total);
    }

    #[Test]
    public function it_annualizes_equity_gain_over_vesting(): void
    {
        $total = $this->calculator()->compute(
            currency: 'USD',
            base: 150_000,
            bonus: 0,
            signingBonus: 0,
            equityShares: 40_000,
            equityStrike: 1.0,
            equityAssumedPrice: 5.0,
            vestingYears: 4,
        );

        // 150k + ((5 - 1) * 40000 / 4) = 150k + 40k = 190k
        $this->assertSame(190_000, $total);
    }

    #[Test]
    public function underwater_equity_contributes_zero(): void
    {
        $total = $this->calculator()->compute(
            currency: 'USD',
            base: 150_000,
            bonus: 0,
            signingBonus: 0,
            equityShares: 10_000,
            equityStrike: 8.0,
            equityAssumedPrice: 5.0,
            vestingYears: 4,
        );

        $this->assertSame(150_000, $total);
    }

    #[Test]
    public function it_converts_non_usd_currency_to_usd(): void
    {
        // EUR rate is 1.08 in config/pipeline.php.
        $total = $this->calculator()->compute(
            currency: 'EUR',
            base: 100_000,
            bonus: 0,
            signingBonus: 0,
            equityShares: null,
            equityStrike: null,
            equityAssumedPrice: null,
            vestingYears: null,
        );

        $this->assertSame(108_000, $total);
    }

    #[Test]
    public function missing_vesting_treats_amortized_components_as_single_year(): void
    {
        $total = $this->calculator()->compute(
            currency: 'USD',
            base: 100_000,
            bonus: 0,
            signingBonus: 50_000,
            equityShares: null,
            equityStrike: null,
            equityAssumedPrice: null,
            vestingYears: null,
        );

        // No vesting => signing not divided => 100k + 50k = 150k
        $this->assertSame(150_000, $total);
    }

    #[Test]
    public function unknown_currency_falls_back_to_one_to_one(): void
    {
        $total = $this->calculator()->compute(
            currency: 'ZZZ',
            base: 90_000,
            bonus: 0,
            signingBonus: 0,
            equityShares: null,
            equityStrike: null,
            equityAssumedPrice: null,
            vestingYears: null,
        );

        $this->assertSame(90_000, $total);
    }

    #[Test]
    public function all_null_cash_yields_zero(): void
    {
        $total = $this->calculator()->compute(
            currency: 'USD',
            base: null,
            bonus: null,
            signingBonus: null,
            equityShares: null,
            equityStrike: null,
            equityAssumedPrice: null,
            vestingYears: null,
        );

        $this->assertSame(0, $total);
    }
}
