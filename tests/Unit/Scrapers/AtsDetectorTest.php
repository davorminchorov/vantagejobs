<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers;

use App\Scrapers\AtsDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AtsDetectorTest extends TestCase
{
    /**
     * @return list<array{string, ?array{ats_type: string, ats_slug: string}}>
     */
    public static function urlCases(): array
    {
        return [
            ['https://boards.greenhouse.io/acme', ['ats_type' => 'greenhouse', 'ats_slug' => 'acme']],
            ['https://job-boards.greenhouse.io/stripe', ['ats_type' => 'greenhouse', 'ats_slug' => 'stripe']],
            ['https://boards.greenhouse.io/embed/job_board?for=mercury', ['ats_type' => 'greenhouse', 'ats_slug' => 'mercury']],
            ['https://jobs.lever.co/figma', ['ats_type' => 'lever', 'ats_slug' => 'figma']],
            ['https://jobs.lever.co/UPPER-CASE', ['ats_type' => 'lever', 'ats_slug' => 'upper-case']],
            ['https://jobs.ashbyhq.com/linear', ['ats_type' => 'ashby', 'ats_slug' => 'linear']],

            // Workable
            ['https://apply.workable.com/acme/', ['ats_type' => 'workable', 'ats_slug' => 'acme']],
            ['https://apply.workable.com/acme/j/ABCDEF12/', ['ats_type' => 'workable', 'ats_slug' => 'acme']],
            ['https://acme.workable.com/jobs', ['ats_type' => 'workable', 'ats_slug' => 'acme']],
            ['https://jobs.workable.com/api/v1/widget/accounts/acme', ['ats_type' => 'workable', 'ats_slug' => 'acme']],

            // SmartRecruiters
            ['https://jobs.smartrecruiters.com/Globex', ['ats_type' => 'smartrecruiters', 'ats_slug' => 'globex']],
            ['https://careers-page.smartrecruiters.com/initech', ['ats_type' => 'smartrecruiters', 'ats_slug' => 'initech']],
            ['https://api.smartrecruiters.com/v1/companies/Hooli/postings', ['ats_type' => 'smartrecruiters', 'ats_slug' => 'hooli']],

            // Personio
            ['https://acme.jobs.personio.de', ['ats_type' => 'personio', 'ats_slug' => 'acme']],
            ['https://acme.jobs.personio.com/', ['ats_type' => 'personio', 'ats_slug' => 'acme']],

            // Recruitee
            ['https://acme.recruitee.com/', ['ats_type' => 'recruitee', 'ats_slug' => 'acme']],
            ['https://acme.recruitee.com/api/offers/', ['ats_type' => 'recruitee', 'ats_slug' => 'acme']],

            // Teamtailor
            ['https://acme.teamtailor.com/jobs.rss', ['ats_type' => 'teamtailor', 'ats_slug' => 'acme']],

            ['https://example.com/careers', null],
            ['https://career.acme.com/', null],
            ['', null],
        ];
    }

    #[Test]
    #[DataProvider('urlCases')]
    public function detect_matches_known_ats_patterns(string $url, ?array $expected): void
    {
        $detector = new AtsDetector();

        $this->assertSame($expected, $detector->detect($url));
    }

    #[Test]
    public function detect_returns_null_for_null_input(): void
    {
        $this->assertNull((new AtsDetector())->detect(null));
    }
}
