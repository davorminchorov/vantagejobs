<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\ElixirJobs;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one parsed `<item>` from the Elixir Jobs RSS feed into a SourceContext.
 *
 * Pure — no HTTP, no DB. The scraper hands us an associative array shaped
 * like:
 *
 *   [
 *     'guid'        => 'https://elixirjobs.net/offers/hunit-senior-elixir-developer-e40f86a8',
 *     'title'       => 'Senior Elixir Developer',
 *     'link'        => 'https://elixirjobs.net/offers/hunit-senior-elixir-developer-e40f86a8',
 *     'description' => '<font ...>...</font> ...',
 *     'pubDate'     => '07 May 2026 09:51:29 +0000',   // no day-of-week prefix
 *   ]
 *
 * Title is the role name only — no company embedded. The non-standard pubDate
 * format (no day-of-week prefix) is still handled correctly by Carbon::parse.
 */
final readonly class ElixirJobsContextBuilder
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
        $url = $link !== '' ? $link : ($guid !== '' ? $guid : null);

        return new SourceContext(
            sourceType: AggregatorSourceType::ELIXIR_JOBS,
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
