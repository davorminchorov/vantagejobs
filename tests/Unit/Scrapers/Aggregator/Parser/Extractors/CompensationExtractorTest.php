<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser\Extractors;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\Extractors\CompensationExtractor;
use App\Scrapers\Aggregator\Parser\SourceContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompensationExtractorTest extends TestCase
{
    #[Test]
    public function himalayas_structured_salary_is_explicit(): void
    {
        $field = $this->extract([
            'salaryMin' => 180_000,
            'salaryMax' => 260_000,
            'salaryCurrency' => 'USD',
        ]);

        $this->assertSame(0.95, $field->confidence);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame(180_000, $field->value['min']);
        $this->assertSame(260_000, $field->value['max']);
        $this->assertSame('USD', $field->value['currency']);
        $this->assertSame('annual', $field->value['interval']);
        $this->assertSame(180_000, $field->value['min_usd']);
        $this->assertSame(260_000, $field->value['max_usd']);
    }

    #[Test]
    public function himalayas_eur_normalizes_to_usd(): void
    {
        $field = $this->extract([
            'salaryMin' => 80_000,
            'salaryMax' => 120_000,
            'salaryCurrency' => 'EUR',
        ]);

        $this->assertSame('EUR', $field->value['currency']);
        $this->assertSame(86_400, $field->value['min_usd']);
        $this->assertSame(129_600, $field->value['max_usd']);
    }

    #[Test]
    public function remoteok_min_max_is_explicit_usd(): void
    {
        $field = $this->extract([
            'salary_min' => 150_000,
            'salary_max' => 200_000,
        ]);

        $this->assertSame(0.9, $field->confidence);
        $this->assertSame('USD', $field->value['currency']);
        $this->assertSame(150_000, $field->value['min_usd']);
    }

    #[Test]
    public function remotive_salary_string_dollar_range(): void
    {
        $field = $this->extract([
            'salary' => '$150,000 - $200,000',
        ]);

        $this->assertSame(ExtractionSource::INFERRED, $field->source);
        $this->assertSame(0.7, $field->confidence);
        $this->assertSame(150_000, $field->value['min']);
        $this->assertSame(200_000, $field->value['max']);
        $this->assertSame('USD', $field->value['currency']);
    }

    #[Test]
    public function remotive_salary_string_euro_k_range(): void
    {
        $field = $this->extract([
            'salary' => '€80k–€120k',
        ]);

        $this->assertSame(80_000, $field->value['min']);
        $this->assertSame(120_000, $field->value['max']);
        $this->assertSame('EUR', $field->value['currency']);
    }

    #[Test]
    public function open_ended_salary_yields_min_only(): void
    {
        $field = $this->extract([
            'salary' => '$200k+',
        ]);

        $this->assertSame(200_000, $field->value['min']);
        $this->assertNull($field->value['max']);
    }

    #[Test]
    public function hourly_interval_detected_in_salary_string(): void
    {
        $field = $this->extract([
            'salary' => '$80 - $120 per hour',
        ]);

        $this->assertSame('hourly', $field->value['interval']);
    }

    #[Test]
    public function equity_detected_in_body(): void
    {
        $field = $this->extract(
            structured: ['salary_min' => 150_000, 'salary_max' => 200_000],
            bodyText: 'Strong base plus generous equity (stock options).',
        );

        $this->assertTrue($field->value['equity']);
    }

    #[Test]
    public function equity_false_when_no_mention(): void
    {
        $field = $this->extract(
            structured: ['salary_min' => 150_000, 'salary_max' => 200_000],
            bodyText: 'Competitive base salary.',
        );

        $this->assertFalse($field->value['equity']);
    }

    #[Test]
    public function body_only_range_is_lowest_confidence(): void
    {
        $field = $this->extract(
            structured: [],
            bodyText: 'Senior engineer, salary $150k-$200k, with equity.',
        );

        $this->assertSame(ExtractionSource::INFERRED, $field->source);
        $this->assertSame(0.5, $field->confidence);
        $this->assertSame(150_000, $field->value['min']);
        $this->assertSame(200_000, $field->value['max']);
    }

    #[Test]
    public function missing_when_no_comp_signal(): void
    {
        $field = $this->extract(structured: ['title' => 'Engineer']);

        $this->assertTrue($field->isMissing());
    }

    #[Test]
    public function himalayas_path_wins_when_remoteok_keys_also_present(): void
    {
        $field = $this->extract([
            'salaryMin' => 100_000,
            'salaryCurrency' => 'GBP',
            'salary_min' => 200_000,
        ]);

        // Himalayas branch fired first → GBP, not USD.
        $this->assertSame('GBP', $field->value['currency']);
        $this->assertSame(100_000, $field->value['min']);
    }

    #[Test]
    public function unknown_currency_returns_null_usd(): void
    {
        $field = $this->extract([
            'salaryMin' => 100_000,
            'salaryMax' => 150_000,
            'salaryCurrency' => 'XYZ',
        ]);

        $this->assertSame('XYZ', $field->value['currency']);
        $this->assertNull($field->value['min_usd']);
        $this->assertNull($field->value['max_usd']);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function extract(array $structured, ?string $bodyText = null): \App\Scrapers\Aggregator\Parser\ExtractedField
    {
        return (new CompensationExtractor())->extract(new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: $structured,
            bodyText: $bodyText,
        ));
    }
}
