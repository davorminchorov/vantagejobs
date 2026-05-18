<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser;

use InvalidArgumentException;

/**
 * A single parsed-field result produced by one Extractor.
 *
 * Confidence is honest. When the extractor is guessing, return a low score —
 * the UI surfaces low-confidence fields for review and that signal is useless
 * if we lie about it.
 *
 * MISSING is the explicit "nothing here" value: extractors that find no
 * signal should return ExtractedField::missing() rather than skipping —
 * downstream code uses the placeholder to render "unparsed" indicators.
 */
final readonly class ExtractedField
{
    public function __construct(
        public string $field,
        public mixed $value,
        public float $confidence,
        public ExtractionSource $source,
        public ?string $evidence = null,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException(
                "ExtractedField confidence must be in [0.0, 1.0]; got {$confidence}.",
            );
        }
    }

    public static function missing(string $field): self
    {
        return new self(
            field: $field,
            value: null,
            confidence: 0.0,
            source: ExtractionSource::MISSING,
        );
    }

    public function isMissing(): bool
    {
        return $this->source === ExtractionSource::MISSING;
    }

    /**
     * Serializable form for the `parsed` jsonb column.
     *
     * @return array{value: mixed, confidence: float, source: string, evidence: ?string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'confidence' => $this->confidence,
            'source' => $this->source->value,
            'evidence' => $this->evidence,
        ];
    }
}
