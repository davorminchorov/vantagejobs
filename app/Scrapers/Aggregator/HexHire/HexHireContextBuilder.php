<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HexHire;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one HexHire RSS `<item>` array into a SourceContext.
 *
 * Pure. Splits `"$role at $company"` titles, normalises multiple
 * `<category>` elements into a tag list, and parses pubDate as UTC.
 */
final class HexHireContextBuilder
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function build(array $item): SourceContext
    {
        $rawTitle = trim((string) ($item['title'] ?? ''));
        [$role, $company] = $this->splitTitleOnFirstAt($rawTitle);

        $descriptionHtml = (string) ($item['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $pubDate = $item['pubDate'] ?? null;
        if (is_string($pubDate) && $pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $guid = trim((string) ($item['guid'] ?? ''));
        $link = trim((string) ($item['link'] ?? ''));
        $externalId = $guid !== '' ? $guid : ($link !== '' ? $link : null);
        $url = $link !== '' ? $link : $guid;

        /** @var list<string> $categories */
        $categories = isset($item['categories']) && is_array($item['categories'])
            ? array_values(array_filter(array_map(
                static fn ($c): string => is_string($c) ? trim($c) : '',
                $item['categories'],
            ), static fn (string $c): bool => $c !== ''))
            : [];

        $structured = $item;
        $structured['title'] = $role;
        if ($company !== '') {
            $structured['companyName'] = $company;
        }
        $structured['categories'] = $categories;
        $structured['tags'] = $categories;

        return new SourceContext(
            sourceType: AggregatorSourceType::HEXHIRE,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url !== '' ? $url : null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitTitleOnFirstAt(string $title): array
    {
        $pos = mb_strpos($title, ' at ');
        if ($pos === false) {
            return [$title, ''];
        }

        return [
            trim(mb_substr($title, 0, $pos)),
            trim(mb_substr($title, $pos + 4)),
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
