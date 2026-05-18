<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\PythonOrg;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 * Turns one Python.org `<item>` array into a SourceContext.
 *
 * Pure — no HTTP, no DB. The feed gives us only `title`, `link`, `guid`,
 * `description`. Titles follow the convention "$role, $company"; we split
 * on the LAST comma so that roles which themselves contain commas (e.g.
 * "Senior, Backend Engineer") still keep their full role text.
 *
 * external_id: `<guid>` (canonical permalink URL).
 * url: same as external_id.
 * postedAt: not provided by the feed — left null.
 */
final class PythonOrgContextBuilder
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function build(array $item): SourceContext
    {
        $rawTitle = trim((string) ($item['title'] ?? ''));
        [$role, $company] = $this->splitTitleOnLastComma($rawTitle);

        $descriptionHtml = (string) ($item['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $guid = trim((string) ($item['guid'] ?? ''));
        $link = trim((string) ($item['link'] ?? ''));
        $externalId = $guid !== '' ? $guid : ($link !== '' ? $link : null);
        $url = $link !== '' ? $link : $guid;

        $structured = $item;
        $structured['title'] = $role;
        if ($company !== '') {
            $structured['companyName'] = $company;
        }

        return new SourceContext(
            sourceType: AggregatorSourceType::PYTHON_ORG,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: null,
            externalId: $externalId,
            url: $url !== '' ? $url : null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitTitleOnLastComma(string $title): array
    {
        $pos = mb_strrpos($title, ',');
        if ($pos === false) {
            return [$title, ''];
        }

        return [
            trim(mb_substr($title, 0, $pos)),
            trim(mb_substr($title, $pos + 1)),
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
