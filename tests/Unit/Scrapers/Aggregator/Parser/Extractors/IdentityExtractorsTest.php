<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser\Extractors;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\Extractors\CompanyNameExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\DisciplineExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RoleTitleExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\SeniorityExtractor;
use App\Scrapers\Aggregator\Parser\SourceContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityExtractorsTest extends TestCase
{
    /* ----- CompanyName ----- */

    #[Test]
    public function company_name_from_remotive_key(): void
    {
        $field = (new CompanyNameExtractor)->extract($this->ctx(['company_name' => 'Acme Corp']));

        $this->assertSame('Acme Corp', $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame(0.95, $field->confidence);
    }

    #[Test]
    public function company_name_from_himalayas_camel_case(): void
    {
        $this->assertSame('Globex', (new CompanyNameExtractor)->extract($this->ctx(['companyName' => 'Globex']))->value);
    }

    #[Test]
    public function company_name_from_remoteok_key(): void
    {
        $this->assertSame('Initech', (new CompanyNameExtractor)->extract($this->ctx(['company' => 'Initech']))->value);
    }

    #[Test]
    public function company_name_trims_whitespace(): void
    {
        $this->assertSame('Acme', (new CompanyNameExtractor)->extract($this->ctx(['company_name' => '  Acme  ']))->value);
    }

    #[Test]
    public function company_name_missing_when_no_key_present(): void
    {
        $this->assertTrue((new CompanyNameExtractor)->extract($this->ctx([]))->isMissing());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function placeholderCompanyNames(): array
    {
        return [
            ['name'],
            ['Name'],
            ['company'],
            ['Company'],
            ['company name'],
            ['Company Name'],
            ['n/a'],
            ['N/A'],
            ['unknown'],
            ['Unknown'],
            ['null'],
            ['Null'],
            ['  '],
        ];
    }

    #[Test]
    #[DataProvider('placeholderCompanyNames')]
    public function company_name_missing_when_value_is_a_placeholder(string $placeholder): void
    {
        $this->assertTrue((new CompanyNameExtractor)->extract($this->ctx(['company_name' => $placeholder]))->isMissing());
    }

    #[Test]
    public function company_name_falls_through_to_next_key_when_first_key_is_a_placeholder(): void
    {
        $field = (new CompanyNameExtractor)->extract($this->ctx([
            'company_name' => 'N/A',
            'companyName' => 'Globex',
        ]));

        $this->assertSame('Globex', $field->value);
    }

    /* ----- RoleTitle ----- */

    #[Test]
    public function role_title_from_title_key(): void
    {
        $field = (new RoleTitleExtractor)->extract($this->ctx(['title' => 'Staff Engineer, Edge']));
        $this->assertSame('Staff Engineer, Edge', $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
    }

    #[Test]
    public function role_title_from_position_key_for_remoteok(): void
    {
        $this->assertSame('Senior Backend Engineer', (new RoleTitleExtractor)->extract($this->ctx(['position' => 'Senior Backend Engineer']))->value);
    }

    #[Test]
    public function role_title_missing_when_no_key_present(): void
    {
        $this->assertTrue((new RoleTitleExtractor)->extract($this->ctx([]))->isMissing());
    }

    /* ----- Discipline ----- */

    #[Test]
    public function discipline_backend_from_title(): void
    {
        $this->assertSame('backend', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior Backend Engineer']))->value);
    }

    #[Test]
    public function discipline_frontend_from_title(): void
    {
        $this->assertSame('frontend', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior Front-end Engineer']))->value);
    }

    #[Test]
    public function discipline_fullstack_beats_backend_in_title(): void
    {
        $this->assertSame('fullstack', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior Full-Stack Engineer (Backend-leaning)']))->value);
    }

    #[Test]
    public function discipline_mobile_from_ios_title(): void
    {
        $this->assertSame('mobile', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior iOS Engineer']))->value);
    }

    #[Test]
    public function discipline_devops_from_sre_title(): void
    {
        $this->assertSame('devops', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Staff SRE — Production Platform']))->value);
    }

    #[Test]
    public function discipline_data_from_data_engineer_title(): void
    {
        $this->assertSame('data', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior Data Engineer']))->value);
    }

    #[Test]
    public function discipline_ml_from_ml_engineer_title(): void
    {
        $this->assertSame('ml', (new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior ML Engineer (NLP)']))->value);
    }

    #[Test]
    public function discipline_falls_back_to_engineering_low_confidence(): void
    {
        $field = (new DisciplineExtractor)->extract($this->ctx(['title' => 'Software Engineer']));

        $this->assertSame('engineering', $field->value);
        $this->assertSame(0.3, $field->confidence);
    }

    #[Test]
    public function discipline_missing_when_no_engineer_word(): void
    {
        $this->assertTrue((new DisciplineExtractor)->extract($this->ctx(['title' => 'Senior Marketing Manager']))->isMissing());
    }

    /* ----- Seniority ----- */

    #[Test]
    public function seniority_from_himalayas_structured_array(): void
    {
        $field = (new SeniorityExtractor)->extract($this->ctx(['seniority' => ['Senior']]));
        $this->assertSame('senior', $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame(0.9, $field->confidence);
    }

    #[Test]
    public function seniority_staff_beats_senior_in_title(): void
    {
        $this->assertSame('staff', (new SeniorityExtractor)->extract($this->ctx(['title' => 'Senior Staff Engineer']))->value);
    }

    #[Test]
    public function seniority_lead_beats_senior_in_tech_lead_title(): void
    {
        $this->assertSame('lead', (new SeniorityExtractor)->extract($this->ctx(['title' => 'Senior Tech Lead, Platform']))->value);
    }

    #[Test]
    public function seniority_principal_in_title(): void
    {
        $this->assertSame('principal', (new SeniorityExtractor)->extract($this->ctx(['title' => 'Principal Software Engineer']))->value);
    }

    #[Test]
    public function seniority_director_in_title(): void
    {
        $this->assertSame('director', (new SeniorityExtractor)->extract($this->ctx(['title' => 'Director of Engineering']))->value);
    }

    #[Test]
    public function seniority_intern_in_title(): void
    {
        $this->assertSame('intern', (new SeniorityExtractor)->extract($this->ctx(['title' => 'Summer Internship 2026']))->value);
    }

    #[Test]
    public function seniority_missing_on_unadorned_title(): void
    {
        $this->assertTrue((new SeniorityExtractor)->extract($this->ctx(['title' => 'Software Engineer']))->isMissing());
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function ctx(array $structured): SourceContext
    {
        return new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: $structured,
        );
    }
}
