<?php

declare(strict_types=1);

namespace Tests\Unit\Signals;

use App\Signals\Funding\FundedCompanyExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FundedCompanyExtractorTest extends TestCase
{
    #[Test]
    #[DataProvider('headlines')]
    public function it_finds_the_company_that_raised(string $headline, ?string $expected): void
    {
        $this->assertSame($expected, (new FundedCompanyExtractor)->extract($headline));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function headlines(): array
    {
        return [
            'plain' => ['Harbinger raises $240M after expanding into defense', 'Harbinger'],
            'two words' => ['Titan OS raises $46 million to expand its smart TV OS internationally', 'Titan OS'],
            'lower-case descriptor' => ['Cambridge biotech startup Neela raises £2.1m to turn waste into aviation fuel', 'Neela'],
            'secures' => ['Itoflow secures £1.8m to build AI agents for investor portfolio management', 'Itoflow'],
            'hyphenated name' => ['EOS-X Space raises $120M at $1.2B valuation ahead of its first balloon flight', 'EOS-X Space'],
            'title case with descriptor' => ['AI Firm Replit Raises $400M Series D Led by Qatar Investment Authority', 'Replit'],
            'quoted lower-case descriptor' => ['‘Vibe-coding’ start-up Replit raises $400m in Series D funding', 'Replit'],
            'two-word name' => ['First Table raises $12 million NZD to fuel expansion across the UK and Ireland', 'First Table'],
            'possessive' => ["Siggy's founders raise \$6M", null],
            'founders, not a company' => ['Exclusive: Nurx And Journey Clinical Co-Founders Raise $6M For Mental Health Startup Siggy', null],
            'colon prefix' => ['Exclusive: Physicl raises $10M to make the digital world real enough for robots', 'Physicl'],
            'no funding verb' => ['Report: Accel is to lead a $5B round for Thinking Machines Lab at $40B valuation', null],
            'other company leads' => ['Bain Capital Tech Opportunities leads Mixpanel’s $200M Series C', null],
            'connector inside the name' => ['Bank of Dave secures £20M growth round', 'Bank of Dave'],
        ];
    }
}
