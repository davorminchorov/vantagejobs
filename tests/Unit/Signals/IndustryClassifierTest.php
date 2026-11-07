<?php

declare(strict_types=1);

namespace Tests\Unit\Signals;

use App\Signals\Funding\IndustryClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndustryClassifierTest extends TestCase
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $industries
     */
    #[Test]
    #[DataProvider('articles')]
    public function it_maps_feed_tags_to_industries_and_a_software_verdict(array $categories, string $headline, ?string $summary, array $industries, ?bool $isSoftware): void
    {
        $result = (new IndustryClassifier)->classify($categories, $headline, $summary);

        $this->assertSame($industries, $result['industries']);
        $this->assertSame($isSoftware, $result['is_software']);
    }

    /**
     * @return array<string, array{list<string>, string, string|null, list<string>, bool|null}>
     */
    public static function articles(): array
    {
        return [
            'software from tags, noise ignored' => [
                ['Cybersecurity', 'Funding', 'Investment News', 'Prevalent AI'],
                'Prevalent AI secures £16.1m to accelerate AI context engine', null,
                ['ai', 'security'], true,
            ],
            'deep tech beats the AI tag' => [
                ['Biotech', 'Cambridge', 'Funding', 'AI', 'Aviation fuel', 'climate tech', 'Elbow Beach Capital'],
                'Cambridge biotech startup Neela raises £2.1m', null,
                ['ai', 'biotech', 'climate_energy'], false,
            ],
            'software plus deep tech is unclear' => [
                ['Fintech', 'Robotics'],
                'Acme raises $5M', null,
                ['fintech', 'robotics_hardware'], null,
            ],
            'neutral only is unclear' => [
                ['Fundraising', 'TC', 'tvs', 'Highland Europe'],
                'Titan OS raises $46 million', null,
                ['commerce_consumer'], null,
            ],
            'falls back to headline and summary when no tag matches' => [
                ['General', 'In Brief'],
                'VÆRIDION raises €30M for zero-emission regional flights',
                'A Munich-based aerospace startup on a mission to make zero-emission flights.',
                ['defence_space'], false,
            ],
            'nothing at all' => [
                ['General'], 'Acme raises $5M', null, [], null,
            ],
            'machine learning is AI, not education' => [
                ['Machine learning'], 'Acme raises $5M', null, ['ai'], true,
            ],
        ];
    }
}
