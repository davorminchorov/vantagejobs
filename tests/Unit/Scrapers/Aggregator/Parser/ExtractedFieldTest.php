<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser;

use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtractedFieldTest extends TestCase
{
    #[Test]
    public function constructs_with_valid_confidence(): void
    {
        $field = new ExtractedField('comp_min', 150000, 0.9, ExtractionSource::EXPLICIT, '"salary": 150000');

        $this->assertSame('comp_min', $field->field);
        $this->assertSame(150000, $field->value);
        $this->assertSame(0.9, $field->confidence);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame('"salary": 150000', $field->evidence);
        $this->assertFalse($field->isMissing());
    }

    #[Test]
    public function rejects_confidence_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExtractedField('x', null, -0.1, ExtractionSource::INFERRED);
    }

    #[Test]
    public function rejects_confidence_above_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExtractedField('x', null, 1.01, ExtractionSource::INFERRED);
    }

    #[Test]
    public function missing_factory_produces_a_zero_confidence_placeholder(): void
    {
        $field = ExtractedField::missing('comp_min');

        $this->assertTrue($field->isMissing());
        $this->assertNull($field->value);
        $this->assertSame(0.0, $field->confidence);
        $this->assertSame(ExtractionSource::MISSING, $field->source);
    }

    #[Test]
    public function to_array_shape_matches_parsed_jsonb_contract(): void
    {
        $field = new ExtractedField('region', 'EMEA', 0.7, ExtractionSource::INFERRED, 'Europe Only');

        $this->assertSame([
            'value' => 'EMEA',
            'confidence' => 0.7,
            'source' => 'inferred',
            'evidence' => 'Europe Only',
        ], $field->toArray());
    }

    #[Test]
    public function extraction_source_priority_ordering(): void
    {
        $this->assertGreaterThan(
            ExtractionSource::INFERRED->priority(),
            ExtractionSource::EXPLICIT->priority(),
        );
        $this->assertGreaterThan(
            ExtractionSource::DEFAULT->priority(),
            ExtractionSource::INFERRED->priority(),
        );
        $this->assertGreaterThan(
            ExtractionSource::MISSING->priority(),
            ExtractionSource::DEFAULT->priority(),
        );
    }
}
