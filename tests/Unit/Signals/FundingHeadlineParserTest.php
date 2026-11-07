<?php

declare(strict_types=1);

namespace Tests\Unit\Signals;

use App\Signals\Funding\FundingHeadlineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FundingHeadlineParserTest extends TestCase
{
    /**
     * @param  array{headline?: string, publisher?: string|null, stage?: string|null, amount_usd?: int|null, amount_label?: string|null}  $expected
     */
    #[Test]
    #[DataProvider('headlines')]
    public function it_extracts_round_amount_and_publisher(string $title, array $expected): void
    {
        $parsed = (new FundingHeadlineParser)->parse($title);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $parsed[$key], "{$key} for: {$title}");
        }
    }

    /**
     * @return array<string, array{string, array<string, string|int|null>}>
     */
    public static function headlines(): array
    {
        return [
            'raise before valuation' => [
                'Vanta: $150 Million Series D Funding Raised At $4.15 Billion Valuation For AI-Based Trust Management Platform - Pulse 2.0',
                ['headline' => 'Vanta: $150 Million Series D Funding Raised At $4.15 Billion Valuation For AI-Based Trust Management Platform', 'publisher' => 'Pulse 2.0', 'stage' => 'Series D', 'amount_usd' => 150_000_000, 'amount_label' => '$150M'],
            ],
            'raise with valuation right after' => [
                'Replit: $250 Million At $3 Billion Valuation Raised For Scaling Enterprise AI Development - Pulse 2.0',
                ['stage' => null, 'amount_usd' => 250_000_000, 'amount_label' => '$250M'],
            ],
            'valuation only' => [
                'CrowdStrike-backed compliance startup Vanta valued at $4 billion in new funding round - CNBC',
                ['publisher' => 'CNBC', 'amount_usd' => null, 'amount_label' => null],
            ],
            'valuing wording after the raise' => [
                'Orrick Advises Stripe on $245 Million Series E, Valuing Company at $20 Billion - Orrick',
                ['stage' => 'Series E', 'amount_usd' => 245_000_000],
            ],
            'shorthand valuation' => [
                'Helsinki & San Francisco-based product workflow platform reaches $1.25B valuation in Series C - - ArcticStartup',
                ['headline' => 'Helsinki & San Francisco-based product workflow platform reaches $1.25B valuation in Series C', 'publisher' => 'ArcticStartup', 'stage' => 'Series C', 'amount_usd' => null],
            ],
            'euro amount is not usd' => [
                'ManoMano Secures €60 Million in Series C Funding Led by General Atlantic - General Atlantic',
                ['stage' => 'Series C', 'amount_usd' => null, 'amount_label' => '€60M'],
            ],
            'compact amount' => [
                'Acme raised $20M Series B',
                ['publisher' => null, 'stage' => 'Series B', 'amount_usd' => 20_000_000, 'amount_label' => '$20M'],
            ],
            'decimal billions' => [
                'Databricks raises $1.6bn - FT',
                ['amount_usd' => 1_600_000_000, 'amount_label' => '$1.6B'],
            ],
            'seed and pre-seed' => [
                'Ovoko closes pre-seed round of $500,000',
                ['stage' => 'Pre-seed', 'amount_usd' => 500_000, 'amount_label' => '$500k'],
            ],
            'plain seed' => [
                'Tighten lands seed funding',
                ['stage' => 'Seed', 'amount_usd' => null],
            ],
            'no money' => [
                'Plaid Achieves New Funding Round - Financial IT',
                ['stage' => null, 'amount_usd' => null, 'amount_label' => null],
            ],
        ];
    }
}
