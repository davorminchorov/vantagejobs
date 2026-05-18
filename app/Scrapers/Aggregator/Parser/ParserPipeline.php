<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;

/**
 * Runs a fixed set of extractors against a SourceContext and merges the
 * results into a ParsedListing.
 *
 * Merge rule when multiple extractors target the same field:
 *   1. Higher confidence wins.
 *   2. On equal confidence, higher ExtractionSource priority wins
 *      (EXPLICIT > INFERRED > DEFAULT > MISSING).
 *   3. On total tie, the first-registered extractor wins (stable).
 *
 * MISSING fields are kept only if no non-MISSING extractor wrote to that
 * field — they exist so the UI can render "unparsed" placeholders without
 * the pipeline silently dropping the slot.
 */
final readonly class ParserPipeline
{
    /**
     * @param  list<Extractor>  $extractors
     */
    public function __construct(private array $extractors) {}

    public function parse(SourceContext $ctx): ParsedListing
    {
        /** @var array<string, ExtractedField> $winners */
        $winners = [];

        foreach ($this->extractors as $extractor) {
            $result = $extractor->extract($ctx);
            $field = $result->field;

            if (! isset($winners[$field])) {
                $winners[$field] = $result;

                continue;
            }

            if ($this->shouldReplace($winners[$field], $result)) {
                $winners[$field] = $result;
            }
        }

        return new ParsedListing($winners);
    }

    private function shouldReplace(ExtractedField $current, ExtractedField $candidate): bool
    {
        if ($candidate->confidence > $current->confidence) {
            return true;
        }

        if ($candidate->confidence < $current->confidence) {
            return false;
        }

        return $candidate->source->priority() > $current->source->priority();
    }
}
