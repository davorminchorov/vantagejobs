<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Himalayas;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Himalayas `jobs[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Himalayas ships structured JSON whose keys the
 * extractors already read directly (`companyName`, `title`, `seniority[]`,
 * `employmentType[]`, `salaryMin/Max/Currency`, `timezoneRestrictions[]`,
 * `locationRestrictions[]`). We only:
 *
 *   - Strip HTML from `description` for bodyText (visa-sponsorship and
 *     body-fallback extractors read it).
 *   - Parse `publishedDate` as UTC.
 *   - Use `guid` as externalId and `applicationLink` as url — the canonical
 *     outbound link is the employer's site, not Himalayas.
 */
final class HimalayasContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $published = $job['publishedDate'] ?? null;
        if (is_string($published) && $published !== '') {
            try {
                $postedAt = CarbonImmutable::parse($published, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($job['guid']) && is_string($job['guid']) && $job['guid'] !== ''
            ? $job['guid']
            : null;

        $url = isset($job['applicationLink']) && is_string($job['applicationLink']) && $job['applicationLink'] !== ''
            ? $job['applicationLink']
            : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::HIMALAYAS,
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
