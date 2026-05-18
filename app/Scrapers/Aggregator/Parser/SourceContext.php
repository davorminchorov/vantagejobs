<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser;

use App\Enums\AggregatorSourceType;
use Carbon\CarbonImmutable;

/**
 * Per-listing input to the ParserPipeline. Carries everything an extractor
 * might need; extractors decide which of structured / bodyText to read.
 *
 *   structured : the original source record (Remotive job, Himalayas job,
 *                RemoteOK item, We Work Remotely feed item parsed to assoc,
 *                HackerNews comment).
 *   bodyText   : plain text body, HTML stripped + entities decoded.
 *                Always present for HackerNews and We Work Remotely;
 *                optional for the JSON sources that already carry the
 *                description in `structured`.
 *   bodyHtml   : raw HTML body when available, for extractors that need
 *                anchor hrefs (apply links etc).
 */
final readonly class SourceContext
{
    /**
     * @param  array<string, mixed>  $structured
     */
    public function __construct(
        public AggregatorSourceType $sourceType,
        public array $structured,
        public ?string $bodyText = null,
        public ?string $bodyHtml = null,
        public ?CarbonImmutable $postedAt = null,
        public ?string $externalId = null,
        public ?string $url = null,
    ) {}
}
