<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Jobicy `jobs[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Jobicy ships structured JSON, so the structured
 * channel passes through largely as-is. We do:
 *
 *   - Strip HTML from `jobDescription` for bodyText (visa-sponsorship +
 *     body-fallback compensation extractors read it).
 *   - Parse `pubDate` as UTC.
 *   - Coerce salary fields to a numeric `compensation` sub-array so the
 *     existing CompensationExtractor picks them up via the `structured`
 *     channel.
 */
final class JobicyContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['jobDescription'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $pubDate = $job['pubDate'] ?? null;
        if (is_string($pubDate) && $pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($job['id']) ? (string) $job['id'] : null;
        $url = isset($job['url']) && is_string($job['url']) ? $job['url'] : null;

        $structured = $job;
        $structured['compensation'] = $this->extractCompensation($job);

        return new SourceContext(
            sourceType: AggregatorSourceType::JOBICY,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url,
        );
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{min: ?int, max: ?int, currency: ?string, period: ?string}
     */
    private function extractCompensation(array $job): array
    {
        $min = $job['salaryMin'] ?? null;
        $max = $job['salaryMax'] ?? null;
        $currency = $job['salaryCurrency'] ?? null;
        $period = $job['salaryPeriod'] ?? null;

        return [
            'min' => is_numeric($min) ? (int) $min : null,
            'max' => is_numeric($max) ? (int) $max : null,
            'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
            'period' => is_string($period) && $period !== '' ? strtolower($period) : null,
        ];
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
