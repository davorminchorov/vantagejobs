<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WorkingNomads;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Working Nomads job record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Working Nomads has no stable numeric id, so the
 * scraper hashes the canonical `url` for external_id; we mirror that here so
 * the SourceContext can stand on its own. `description` is HTML, stripped
 * for bodyText so the visa-sponsorship + body-fallback compensation
 * extractors have plain prose to scan. `pub_date` is parsed as UTC.
 */
final readonly class WorkingNomadsContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $pubDate = $job['pub_date'] ?? null;
        if (is_string($pubDate) && $pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $url = isset($job['url']) && is_string($job['url']) && $job['url'] !== ''
            ? $job['url']
            : null;
        $externalId = $url !== null ? sha1($url) : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::WORKINGNOMADS,
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
