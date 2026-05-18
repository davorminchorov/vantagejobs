<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Adzuna;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Adzuna `results[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Adzuna ships structured JSON with `id`, `title`,
 * `description`, `company.display_name`, `location.area` / `display_name`,
 * `category.label`, `created`, `redirect_url`, `salary_min`, `salary_max`,
 * `contract_type`, `contract_time`. The country code isn't on the record
 * itself, so the scraper injects it before handing the array off — that
 * way the downstream extractors can read `country` consistently.
 */
final class AdzunaContextBuilder
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function build(array $result, string $country): SourceContext
    {
        $result['country'] = $country;

        // Flatten Adzuna's nested fields into the flat string keys the
        // shared extractors already understand. Adzuna ships
        // `company.display_name`, `location.display_name`, `category.label`
        // — the parser pipeline reads `company`, `location`, etc as strings,
        // so we'd otherwise hit "Array to string conversion".
        $company = $result['company'] ?? null;
        if (is_array($company) && isset($company['display_name']) && is_string($company['display_name'])) {
            $result['company_name'] = $company['display_name'];
        }

        $location = $result['location'] ?? null;
        if (is_array($location)) {
            if (isset($location['display_name']) && is_string($location['display_name'])) {
                $result['location'] = $location['display_name'];
            } else {
                // Don't leave an array under the `location` key — the
                // RemoteExtractor casts it to string unconditionally.
                $result['location'] = '';
            }
        }

        $descriptionHtml = (string) ($result['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $created = $result['created'] ?? null;
        if (is_string($created) && $created !== '') {
            try {
                $postedAt = CarbonImmutable::parse($created);
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($result['id']) ? (string) $result['id'] : null;
        $url = isset($result['redirect_url']) && is_string($result['redirect_url'])
            ? $result['redirect_url']
            : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::ADZUNA,
            structured: $result,
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
