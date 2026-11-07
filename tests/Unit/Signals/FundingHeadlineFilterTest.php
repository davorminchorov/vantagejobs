<?php

declare(strict_types=1);

namespace Tests\Unit\Signals;

use App\Signals\Funding\FundingHeadlineFilter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FundingHeadlineFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('headlines')]
    public function it_decides_which_headlines_are_funding_news(string $company, string $title, bool $expected): void
    {
        $accepted = (new FundingHeadlineFilter)->accepts($title, $company, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame($expected, $accepted, $title);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function headlines(): array
    {
        return [
            'raised with an amount' => ['Acme', 'Acme raised $20M to expand in Europe - TechCrunch', true],
            'raised with a round' => ['Acme', 'Acme raised seed money from angels - Sifted', true],
            'raised without amount or round' => ['Acme', 'Acme raised the bar for developer tools - The Verge', false],
            'price target raised' => ['Airbnb', 'Airbnb price target raised to $230 from $210 at Oppenheimer - Yahoo Finance', false],
            'raised outlook' => ['Linear', 'NFI: Linear production lifted Q3 profitability and raised the FY2026 Adjusted EBITDA floor - TradingView', false],
            'raised guidance with a figure' => ['WEX', 'WEX raised revenue guidance to $2.6B - Simply Wall St', false],
            'raised and only valuation' => ['Acme', 'Acme raised valuation to $3B - Reuters', false],
            'series keyword is enough' => ['Acme', 'Acme lands Series B to grow sales - Sifted', true],
            'funding round keyword is enough' => ['Plaid', 'Plaid Achieves $8 Billion Valuation Following New Funding Round - Financial IT', true],
            'directory site' => ['Vercel', 'Vercel - 2026 Funding Rounds & List of Investors - Tracxn', false],
            'company not named' => ['Laravel', 'CodeRabbit raised $60M and celebrated with a short film - Laravel News', false],
            'no keyword' => ['Acme', 'Acme launches a new product - TechCrunch', false],
            'keyword inside a longer word' => ['Laravel', 'Laravel Developer Publishes Multi-Tenant SaaS Series Based on Hard-Won Lessons - news.lavx.hu', false],
            'tv series' => ['Vimeo', "Vimeo Jumps Into Original Programming With 'High Maintenance' Series About Pot Dealer - IMDb", false],
        ];
    }

    #[Test]
    public function articles_outside_the_age_window_or_undated_are_rejected(): void
    {
        $filter = new FundingHeadlineFilter;
        $title = 'Acme raised $20M Series B - TechCrunch';

        $this->assertTrue($filter->accepts($title, 'Acme', CarbonImmutable::parse('2025-11-08')));
        $this->assertFalse($filter->accepts($title, 'Acme', CarbonImmutable::parse('2025-11-06')));
        $this->assertFalse($filter->accepts($title, 'Acme', null));
    }

    #[Test]
    public function rss_source_host_identifies_directory_sites(): void
    {
        $filter = new FundingHeadlineFilter;

        $this->assertFalse($filter->accepts('Acme closes round - Funding profile', 'Acme', CarbonImmutable::parse('2026-10-01'), 'www.crunchbase.com', 'Funding profile'));
        $this->assertTrue($filter->accepts('Acme closes round - Fortune', 'Acme', CarbonImmutable::parse('2026-10-01'), 'fortune.com', 'Fortune'));
    }
}
