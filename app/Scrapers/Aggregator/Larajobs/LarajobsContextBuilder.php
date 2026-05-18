<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Larajobs;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one parsed `<item>` from the LaraJobs RSS feed into a SourceContext.
 *
 * Pure — no HTTP, no DB. The scraper hands us an associative array shaped
 * like:
 *
 *   [
 *     'guid'        => 'https://larajobs.com/job/3877',
 *     'title'       => 'Design Focused Front-End Developer',
 *     'link'        => 'https://larajobs.com/job/3877',
 *     'description' => '',          // LaraJobs ships empty bodies in the RSS
 *     'pubDate'     => 'Fri, 15 May 2026 13:27:01 +0000',
 *     'category'    => 'Job',
 *     'companyName' => 'Paul Xavier International LLC',  // dc:creator OR job:company
 *     'location'    => 'Remote',
 *     'employment_type' => 'FULL_TIME',
 *     'salary'      => '$40,000-120,000',                 // free-form
 *     'tags'        => ['AWS', 'Frontend', 'Laravel', 'Postgres', 'VueJS'],
 *   ]
 *
 * `companyName` and `tags` are normalised here so CompanyNameExtractor and
 * StackTagsExtractor pick them up directly. Salary is free-form text without
 * min/max separation, so comp falls through as MISSING — the existing
 * CompensationExtractor expects structured numeric fields.
 */
final readonly class LarajobsContextBuilder
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function build(array $item): SourceContext
    {
        $descriptionHtml = isset($item['description']) && is_string($item['description']) ? $item['description'] : '';
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $pubDate = $item['pubDate'] ?? null;
        if (is_string($pubDate) && $pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate);
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $guid = isset($item['guid']) && is_string($item['guid']) ? $item['guid'] : '';
        $link = isset($item['link']) && is_string($item['link']) ? $item['link'] : '';
        $externalId = $guid !== '' ? $guid : ($link !== '' ? $link : null);
        $url = $link !== '' ? $link : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::LARAJOBS,
            structured: $item,
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
