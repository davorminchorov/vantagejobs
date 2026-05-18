<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Arbeitnow `data[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Arbeitnow ships structured JSON; the structured
 * channel passes through, with HTML stripped from `description` for
 * bodyText, and `created_at` (unix seconds) parsed into a CarbonImmutable.
 */
final class ArbeitnowContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $createdAt = $job['created_at'] ?? null;
        if (is_numeric($createdAt)) {
            try {
                $postedAt = CarbonImmutable::createFromTimestampUTC((int) $createdAt);
            } catch (Throwable) {
                $postedAt = null;
            }
        } elseif (is_string($createdAt) && $createdAt !== '') {
            try {
                $postedAt = CarbonImmutable::parse($createdAt, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($job['slug']) && is_string($job['slug']) ? $job['slug'] : null;
        $url = isset($job['url']) && is_string($job['url']) ? $job['url'] : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::ARBEITNOW,
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
