<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Enums\AtsType;

/**
 * Detects the ATS type and board slug from a URL.
 *
 * Phase 1 only inspects the URL itself — Phase 2 will optionally crawl
 * the careers page to follow iframe embeds. Per SOURCES.md §1.1/1.2/1.3.
 */
final class AtsDetector
{
    /**
     * @var list<array{type: AtsType, regex: string}>
     */
    private const PATTERNS = [
        // Greenhouse: boards.greenhouse.io/{slug}, job-boards.greenhouse.io/{slug},
        // or the embed param form boards.greenhouse.io/embed/job_board?for={slug}.
        ['type' => AtsType::GREENHOUSE, 'regex' => '#(?:boards|job-boards)\.greenhouse\.io/(?:embed/job_board\?for=)?([a-z0-9_-]+)#i'],
        // Lever: jobs.lever.co/{slug}
        ['type' => AtsType::LEVER, 'regex' => '#jobs\.lever\.co/([a-z0-9_-]+)#i'],
        // Ashby: jobs.ashbyhq.com/{slug}
        ['type' => AtsType::ASHBY, 'regex' => '#jobs\.ashbyhq\.com/([a-z0-9_-]+)#i'],
    ];

    /**
     * @return ?array{ats_type: string, ats_slug: string}
     */
    public function detect(?string $url): ?array
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern['regex'], $url, $matches) === 1) {
                return [
                    'ats_type' => $pattern['type']->value,
                    'ats_slug' => mb_strtolower($matches[1]),
                ];
            }
        }

        return null;
    }
}
