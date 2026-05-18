<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\WeWorkRemotely;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one parsed `<item>` from a We Work Remotely RSS feed into a
 * SourceContext.
 *
 * Pure — no HTTP, no DB. The scraper hands us an associative array shaped
 * like:
 *
 *   [
 *     'guid'        => 'https://weworkremotely.com/remote-jobs/acme-...',
 *     'title'       => 'Acme: Senior Backend Engineer',
 *     'region'      => 'Worldwide',
 *     'link'        => 'https://weworkremotely.com/...',
 *     'description' => '<p>HTML body</p>',
 *     'pubDate'     => 'Mon, 12 May 2026 10:00:00 +0000',
 *   ]
 *
 * Per SOURCES.md §2.4 the `<title>` is canonically `Company: Role` — we split
 * on the first colon. When no colon is present we fall back to leaving the
 * whole title as the role and the company empty so downstream extractors can
 * still produce something instead of guessing.
 *
 * `<region>` lands in structured.region (RegionExtractor and LocationExtractor
 * both already key on this field — see their docblocks). The RSS carries no
 * salary or structured tags, so comp will be MISSING and stack tags will
 * fall back to body-text inference via StackTagsExtractor's dictionary scan.
 */
final class WeWorkRemotelyContextBuilder
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

        $region = isset($item['region']) && is_string($item['region']) ? trim($item['region']) : '';

        $structured = [
            'title' => $roleTitle,
        ];
        if ($companyName !== '') {
            $structured['company_name'] = $companyName;
        }
        if ($region !== '') {
            $structured['region'] = $region;
        }

        $postedAt = null;
        $pubDate = $item['pubDate'] ?? null;
        if (is_string($pubDate) && $pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate);
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($item['guid']) && is_string($item['guid']) ? $item['guid'] : null;
        $url = isset($item['link']) && is_string($item['link']) ? $item['link'] : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::WEWORKREMOTELY,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url,
        );
    }

    /**
     * Split "Company: Role" on the first colon. Strips surrounding whitespace
     * on both halves. When no colon is present, returns ['', $title] so the
     * whole string becomes the role and company falls through to body-text
     * inference (CompanyNameExtractor will return MISSING).
     *
     * @return array{0: string, 1: string}
     */
    private function splitTitle(string $title): array
    {
        if ($title === '') {
            return ['', ''];
        }

        $pos = strpos($title, ':');
        if ($pos === false) {
            return ['', $title];
        }

        $company = trim(substr($title, 0, $pos));
        $role = trim(substr($title, $pos + 1));

        return [$company, $role];
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
