<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser\Extractors;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\Extractors\ApplyMethodExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\EmploymentTypeExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\VisaSponsorshipExtractor;
use App\Scrapers\Aggregator\Parser\SourceContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetadataExtractorsTest extends TestCase
{
    /* ----- EmploymentType ----- */

    #[Test]
    public function employment_type_from_himalayas_array(): void
    {
        $field = (new EmploymentTypeExtractor())->extract($this->ctx(['employmentType' => ['Full-Time']]));

        $this->assertSame('full_time', $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
    }

    #[Test]
    public function employment_type_from_remotive_job_type(): void
    {
        $this->assertSame('full_time', (new EmploymentTypeExtractor())->extract($this->ctx(['job_type' => 'full_time']))->value);
    }

    #[Test]
    public function employment_type_contract_from_body(): void
    {
        $field = (new EmploymentTypeExtractor())->extract($this->ctx(['title' => 'Backend Contractor (6 months)']));

        $this->assertSame('contract', $field->value);
        $this->assertSame(ExtractionSource::INFERRED, $field->source);
    }

    #[Test]
    public function employment_type_internship_beats_full_time(): void
    {
        // Pattern order in the extractor puts internship before full_time so
        // "summer internship full time" still triages as internship.
        $field = (new EmploymentTypeExtractor())->extract($this->ctx(['title' => 'Summer Internship (Full-Time hours)']));

        $this->assertSame('internship', $field->value);
    }

    #[Test]
    public function employment_type_missing_when_no_signal(): void
    {
        $this->assertTrue((new EmploymentTypeExtractor())->extract($this->ctx(['title' => 'Software Engineer']))->isMissing());
    }

    /* ----- VisaSponsorship ----- */

    #[Test]
    public function visa_positive_signal_in_body(): void
    {
        $field = (new VisaSponsorshipExtractor())->extract($this->ctx(
            structured: ['title' => 'x'],
            bodyText: 'We offer visa sponsorship for the right candidate.',
        ));

        $this->assertTrue($field->value);
        $this->assertSame(ExtractionSource::INFERRED, $field->source);
    }

    #[Test]
    public function visa_h1b_mention_is_positive(): void
    {
        $field = (new VisaSponsorshipExtractor())->extract($this->ctx(
            structured: [],
            bodyText: 'H-1B candidates welcome to apply.',
        ));

        $this->assertTrue($field->value);
    }

    #[Test]
    public function visa_negative_signal_in_body(): void
    {
        $field = (new VisaSponsorshipExtractor())->extract($this->ctx(
            structured: [],
            bodyText: 'US citizens only — we cannot sponsor visas.',
        ));

        $this->assertFalse($field->value);
    }

    #[Test]
    public function visa_positive_wins_when_both_present(): void
    {
        // Reading order: positive regex runs first. Postings that say both
        // "we sponsor" and "must be authorized" mean "yes, we sponsor".
        $field = (new VisaSponsorshipExtractor())->extract($this->ctx(
            structured: [],
            bodyText: 'We sponsor visas. You must be authorized to work in the US after sponsorship.',
        ));

        $this->assertTrue($field->value);
    }

    #[Test]
    public function visa_missing_when_silent(): void
    {
        $this->assertTrue((new VisaSponsorshipExtractor())->extract($this->ctx(structured: [], bodyText: 'Senior role, competitive pay.'))->isMissing());
    }

    /* ----- ApplyMethod ----- */

    #[Test]
    public function apply_method_from_himalayas_application_link(): void
    {
        $field = (new ApplyMethodExtractor())->extract($this->ctx(['applicationLink' => 'https://acme.com/jobs/staff']));

        $this->assertSame('https://acme.com/jobs/staff', $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
    }

    #[Test]
    public function apply_method_from_remoteok_apply_url(): void
    {
        $this->assertSame('https://remoteok.com/apply/123', (new ApplyMethodExtractor())->extract($this->ctx(['apply_url' => 'https://remoteok.com/apply/123']))->value);
    }

    #[Test]
    public function apply_method_from_remotive_url(): void
    {
        $this->assertSame('https://remotive.com/jobs/123', (new ApplyMethodExtractor())->extract($this->ctx(['url' => 'https://remotive.com/jobs/123']))->value);
    }

    #[Test]
    public function apply_method_falls_back_to_context_url(): void
    {
        $ctx = new SourceContext(
            sourceType: AggregatorSourceType::HACKERNEWS,
            structured: [],
            url: 'https://acme.com/careers/staff',
        );

        $field = (new ApplyMethodExtractor())->extract($ctx);
        $this->assertSame('https://acme.com/careers/staff', $field->value);
        $this->assertSame(ExtractionSource::INFERRED, $field->source);
    }

    #[Test]
    public function apply_method_extracts_email_from_body(): void
    {
        $field = (new ApplyMethodExtractor())->extract($this->ctx(
            structured: [],
            bodyText: 'Send your CV to jobs@acme.com — we read everything.',
        ));

        $this->assertSame('email:jobs@acme.com', $field->value);
    }

    #[Test]
    public function apply_method_extracts_url_from_body(): void
    {
        $field = (new ApplyMethodExtractor())->extract($this->ctx(
            structured: [],
            bodyText: 'Apply at https://acme.com/jobs',
        ));

        $this->assertSame('https://acme.com/jobs', $field->value);
    }

    #[Test]
    public function apply_method_missing_when_no_url_and_no_email(): void
    {
        $this->assertTrue((new ApplyMethodExtractor())->extract($this->ctx(structured: [], bodyText: 'No application details given.'))->isMissing());
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function ctx(array $structured, ?string $bodyText = null): SourceContext
    {
        return new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: $structured,
            bodyText: $bodyText,
        );
    }
}
