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
 * sometimes "Role at Company". We split it here (tried in that order: " at "
 * first, then " - ") and set structured.company_name / structured.title from
 * the halves, the same way WeWorkRemotelyContextBuilder and
 * HexHireContextBuilder split their own loose titles. Without this split,
 * CompanyNameExtractor never has a company_name/companyName/company key to
 * read (it does not parse title text itself), so normalized_company_key stays
 * null for every 4dayweek.io listing and the auto-dismiss, dedup, auto-link,
 * and boomerang gates all silently no-op for this entire source. No salary or
 * structured tags in the feed — comp falls through as MISSING and stack tags
 * fall back to body-text inference.
 */
final readonly class FourDayWeekContextBuilder
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function build(array $item): SourceContext
    {
        $rawTitle = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
        [$companyName, $roleTitle] = $this->splitTitle($rawTitle);

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

        $structured = $item;
        $structured['title'] = $roleTitle !== '' ? $roleTitle : $rawTitle;
        if ($companyName !== '') {
            $structured['company_name'] = $companyName;
        }

        return new SourceContext(
            sourceType: AggregatorSourceType::FOURDAYWEEK,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url,
        );
    }

    /**
     * Split a loose "Role at Company" or "Company - Role" title. " at " is
     * tried first since it cannot appear in the "Company - Role" shape's
     * separator; falls back to " - ". When neither separator is present,
     * returns ['', $title] so the whole string becomes the role and company
     * falls through to MISSING rather than guessing.
     *
     * @return array{0: string, 1: string} [companyName, roleTitle]
     */
    private function splitTitle(string $title): array
    {
        if ($title === '') {
            return ['', ''];
        }

        $pos = mb_strpos($title, ' at ');
        if ($pos !== false) {
            return [
                trim(mb_substr($title, $pos + 4)),
                trim(mb_substr($title, 0, $pos)),
            ];
        }

        $pos = mb_strpos($title, ' - ');
        if ($pos !== false) {
            return [
                trim(mb_substr($title, 0, $pos)),
                trim(mb_substr($title, $pos + 3)),
            ];
        }

        return ['', $title];
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
