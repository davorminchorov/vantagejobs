<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Contracts;

use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * One pure-function extractor.
 *
 * Each implementation reads what it needs from SourceContext and returns one
 * ExtractedField. Extractors:
 *
 *   - MUST be deterministic. Same input → same output. No LLM calls, no
 *     network, no clock reads. The PRD is firm on this.
 *   - MUST NOT throw on missing input. Return ExtractedField::missing(field())
 *     instead. The UI uses MISSING to render unparsed indicators.
 *   - SHOULD return honest confidence. A 0.4 explicit-match beats a 0.9
 *     hallucinated one.
 *
 * Multiple extractors MAY target the same field (e.g. a structured-source
 * extractor + a body-text fallback). ParserPipeline merges by highest
 * confidence with ExtractionSource priority as tie-breaker.
 */
interface Extractor
{
    /**
     * The ParsedListing field this extractor writes to.
     * Stable string identifier; ParsedListing accessors key on this.
     */
    public function field(): string;

    public function extract(SourceContext $ctx): ExtractedField;
}
