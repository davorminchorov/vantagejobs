<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\FourDayWeek;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one parsed `<item>` from the 4dayweek.io RSS feed into a SourceContext.
 *
 * Pure — no HTTP, no DB. The scraper hands us an associative array shaped
 * like:
 *
 *   [
 *     'guid'        => '...',
 *     'title'       => 'Acme - Senior Backend Engineer',
 *     'link'        => 'https://4dayweek.io/...',
 *     'description' => '<p>HTML body</p>',
 *     'pubDate'     => 'Mon, 12 May 2026 10:00:00 +0000',
 *     'category'    => 'Engineering',
 *   ]
 *
 * Per SOURCES.md §3.6 the title format is loose — sometimes "Company - Role",
 * sometimes "Role at Company". We pass the raw title through structured so
 * downstream CompanyNameExtractor / RoleTitleExtractor can do their best with
 * the body text and title in parallel. No salary or structured tags in the
 * feed — comp falls through as MISSING and stack tags fall back to body-text
 * inference.
 */
final readonly class FourDayWeekContextBuilder
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
            sourceType: AggregatorSourceType::FOURDAYWEEK,
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
