<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\DjangoNews;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Turns one parsed `<item>` from the Django Job Board RSS feed into a SourceContext.
 *
 * Pure — no HTTP, no DB. The scraper hands us an associative array shaped like:
 *
 *   [
 *     'guid'        => 'https://djangojobboard.com/1358/founding-engineer-mydatavalue/',
 *     'title'       => 'Founding Engineer',   // role only — no company in title
 *     'link'        => 'https://djangojobboard.com/1358/founding-engineer-mydatavalue/',
 *     'description' => '<h2>Job Description</h2>...',  // HTML body
 *   ]
 *
 * The feed carries no pubDate per item and no structured company field — company
 * name is buried in the HTML description. postedAt is therefore null and
 * companyName is not synthesised here; downstream extractors (CompanyNameExtractor)
 * will parse it from bodyText if needed.
 */
final readonly class DjangoNewsContextBuilder
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function build(array $item): SourceContext
    {
        $descriptionHtml = isset($item['description']) && is_string($item['description']) ? $item['description'] : '';
        $bodyText = $this->stripHtml($descriptionHtml);

        $guid = isset($item['guid']) && is_string($item['guid']) ? $item['guid'] : '';
        $link = isset($item['link']) && is_string($item['link']) ? $item['link'] : '';

        // guid is the canonical permalink; fall back to link if absent
        $externalId = $guid !== '' ? $guid : ($link !== '' ? $link : null);
        $url = $guid !== '' ? $guid : ($link !== '' ? $link : null);

        return new SourceContext(
            sourceType: AggregatorSourceType::DJANGO_NEWS,
            structured: $item,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: null,
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
