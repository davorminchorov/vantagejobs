<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\ParsedListing;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use App\Scrapers\Aggregator\Parser\SourceContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserPipelineTest extends TestCase
{
    #[Test]
    public function empty_extractor_set_returns_empty_parsed_listing(): void
    {
        $pipeline = new ParserPipeline([]);
        $listing = $pipeline->parse($this->context());

        $this->assertSame([], $listing->all());
        $this->assertSame([], $listing->toArray());
        $this->assertNull($listing->get('company_name'));
        $this->assertFalse($listing->has('company_name'));
    }

    #[Test]
    public function single_extractor_per_field_passes_through(): void
    {
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('company_name', 'Acme', 0.9, ExtractionSource::EXPLICIT),
            $this->fakeExtractor('role_title', 'Staff Engineer', 0.8, ExtractionSource::EXPLICIT),
        ]);

        $listing = $pipeline->parse($this->context());

        $this->assertSame('Acme', $listing->value('company_name'));
        $this->assertSame('Staff Engineer', $listing->value('role_title'));
        $this->assertTrue($listing->has('company_name'));
    }

    #[Test]
    public function higher_confidence_wins_when_two_extractors_target_one_field(): void
    {
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('comp_min', 100_000, 0.4, ExtractionSource::INFERRED, 'regex on body'),
            $this->fakeExtractor('comp_min', 150_000, 0.95, ExtractionSource::EXPLICIT, 'structured salary'),
        ]);

        $listing = $pipeline->parse($this->context());

        $this->assertSame(150_000, $listing->value('comp_min'));
        $this->assertSame('structured salary', $listing->get('comp_min')?->evidence);
    }

    #[Test]
    public function explicit_beats_inferred_at_equal_confidence(): void
    {
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('location', 'Remote', 0.6, ExtractionSource::INFERRED),
            $this->fakeExtractor('location', 'Berlin', 0.6, ExtractionSource::EXPLICIT),
        ]);

        $this->assertSame('Berlin', $pipeline->parse($this->context())->value('location'));
    }

    #[Test]
    public function lower_confidence_loses_even_when_source_priority_higher(): void
    {
        // EXPLICIT outranks INFERRED on ties only — confidence is the primary key.
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('seniority', 'unknown', 0.2, ExtractionSource::EXPLICIT),
            $this->fakeExtractor('seniority', 'staff', 0.85, ExtractionSource::INFERRED),
        ]);

        $this->assertSame('staff', $pipeline->parse($this->context())->value('seniority'));
    }

    #[Test]
    public function first_registered_wins_on_total_tie(): void
    {
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('region', 'EMEA', 0.5, ExtractionSource::INFERRED),
            $this->fakeExtractor('region', 'Worldwide', 0.5, ExtractionSource::INFERRED),
        ]);

        $this->assertSame('EMEA', $pipeline->parse($this->context())->value('region'));
    }

    #[Test]
    public function missing_field_is_kept_when_no_other_extractor_wrote_it(): void
    {
        $pipeline = new ParserPipeline([
            $this->fakeExtractor(
                field: 'visa_sponsorship',
                value: null,
                confidence: 0.0,
                source: ExtractionSource::MISSING,
            ),
        ]);

        $listing = $pipeline->parse($this->context());

        $this->assertNotNull($listing->get('visa_sponsorship'));
        $this->assertTrue($listing->get('visa_sponsorship')?->isMissing());
        $this->assertFalse($listing->has('visa_sponsorship'));
    }

    #[Test]
    public function non_missing_overrides_missing_even_at_equal_confidence(): void
    {
        // Both 0.0 confidence — INFERRED (priority 2) wins over MISSING (priority 0).
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('apply_method', null, 0.0, ExtractionSource::MISSING),
            $this->fakeExtractor('apply_method', 'email:jobs@acme', 0.0, ExtractionSource::INFERRED),
        ]);

        $field = $pipeline->parse($this->context())->get('apply_method');

        $this->assertSame('email:jobs@acme', $field?->value);
        $this->assertSame(ExtractionSource::INFERRED, $field?->source);
    }

    #[Test]
    public function parsed_listing_to_array_serialises_each_field(): void
    {
        $pipeline = new ParserPipeline([
            $this->fakeExtractor('company_name', 'Acme', 0.9, ExtractionSource::EXPLICIT, 'company_name field'),
            $this->fakeExtractor('region', 'EMEA', 0.7, ExtractionSource::INFERRED, 'Europe Only'),
        ]);

        $array = $pipeline->parse($this->context())->toArray();

        $this->assertSame([
            'company_name' => [
                'value' => 'Acme',
                'confidence' => 0.9,
                'source' => 'explicit',
                'evidence' => 'company_name field',
            ],
            'region' => [
                'value' => 'EMEA',
                'confidence' => 0.7,
                'source' => 'inferred',
                'evidence' => 'Europe Only',
            ],
        ], $array);
    }

    #[Test]
    public function parsed_listing_can_be_constructed_directly_for_test_seams(): void
    {
        $listing = new ParsedListing([
            'company_name' => new ExtractedField('company_name', 'Acme', 0.9, ExtractionSource::EXPLICIT),
        ]);

        $this->assertSame('Acme', $listing->value('company_name'));
    }

    private function context(): SourceContext
    {
        return new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: ['title' => 'irrelevant'],
        );
    }

    private function fakeExtractor(
        string $field,
        mixed $value,
        float $confidence,
        ExtractionSource $source,
        ?string $evidence = null,
    ): Extractor {
        return new class($field, $value, $confidence, $source, $evidence) implements Extractor
        {
            public function __construct(
                private string $fieldName,
                private mixed $value,
                private float $confidence,
                private ExtractionSource $source,
                private ?string $evidence,
            ) {}

            public function field(): string
            {
                return $this->fieldName;
            }

            public function extract(SourceContext $ctx): ExtractedField
            {
                return new ExtractedField(
                    field: $this->fieldName,
                    value: $this->value,
                    confidence: $this->confidence,
                    source: $this->source,
                    evidence: $this->evidence,
                );
            }
        };
    }
}
