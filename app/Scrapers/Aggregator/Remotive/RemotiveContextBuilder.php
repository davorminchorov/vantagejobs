<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Remotive;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Remotive `jobs[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Remotive ships structured JSON, so the structured
 * channel passes through as-is; the extractors already key on Remotive's
 * field names (`company_name`, `title`, `tags`, `salary`, `job_type`,
 * `candidate_required_location`, `url`, `description`). We only do:
 *
 *   - Strip HTML from `description` for bodyText (used by visa-sponsorship
 *     and body-fallback compensation extractors).
 *   - Parse `publication_date` as UTC per SOURCES.md §2.2 ("no timezone —
 *     treat as UTC").
 */
final class RemotiveContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $publication = $job['publication_date'] ?? null;
        if (is_string($publication) && $publication !== '') {
            try {
                $postedAt = CarbonImmutable::parse($publication, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($job['id']) ? (string) $job['id'] : null;
        $url = isset($job['url']) && is_string($job['url']) ? $job['url'] : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: $job,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url,
        );
    }

    private function stripHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $decoded);
        $decoded = (string) preg_replace('/<\s*\/\s*(p|li|div|h\d)\s*>/i', "\n", $decoded);

        $stripped = strip_tags($decoded);
        $stripped = (string) preg_replace('/[ \t]+/', ' ', $stripped);
        $stripped = (string) preg_replace('/\n{3,}/', "\n\n", $stripped);

        return trim($stripped);
    }
}
