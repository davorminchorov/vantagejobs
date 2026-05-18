<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Enums\AtsType;

/**
 * Detects the ATS type and board slug from a URL.
 *
 * Phase 1 only inspects the URL itself — Phase 2 will optionally crawl
 * the careers page to follow iframe embeds. Per SOURCES.md §1.1–1.3 and
 * §3.1–3.5 for the deferred/optional ATS additions.
 *
 * Order matters: more-specific patterns must come before more-generic
 * ones. For example, the `{slug}.workable.com` catch-all sits below
 * `apply.workable.com/{slug}` so the latter doesn't shadow it.
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
        // Workable: apply.workable.com/{slug}, {slug}.workable.com, or
        // jobs.workable.com/api/v1/widget/accounts/{slug} (embedded widgets).
        ['type' => AtsType::WORKABLE, 'regex' => '#apply\.workable\.com/([a-z0-9_-]+)#i'],
        ['type' => AtsType::WORKABLE, 'regex' => '#workable\.com/api/v[0-9]+/widget/accounts/([a-z0-9_-]+)#i'],
        ['type' => AtsType::WORKABLE, 'regex' => '#https?://([a-z0-9_-]+)\.workable\.com#i'],
        // SmartRecruiters: jobs.smartrecruiters.com/{slug} or careers-page.smartrecruiters.com/{slug}.
        ['type' => AtsType::SMARTRECRUITERS, 'regex' => '#(?:jobs|careers-page)\.smartrecruiters\.com/([a-z0-9_-]+)#i'],
        ['type' => AtsType::SMARTRECRUITERS, 'regex' => '#api\.smartrecruiters\.com/v[0-9]+/companies/([a-z0-9_-]+)#i'],
        // Personio: {slug}.jobs.personio.de or .com. The detector returns
        // the slug; the scraper handles the .de → .com fallback.
        ['type' => AtsType::PERSONIO, 'regex' => '#https?://([a-z0-9_-]+)\.jobs\.personio\.(?:de|com)#i'],
        // Recruitee: {slug}.recruitee.com (the api/offers/ path optional).
        ['type' => AtsType::RECRUITEE, 'regex' => '#https?://([a-z0-9_-]+)\.recruitee\.com#i'],
        // Teamtailor: {slug}.teamtailor.com. The career.{slug}.com variant
        // is intentionally NOT auto-detected — too easily a false positive
        // (any career.* domain), so it must be set manually.
        ['type' => AtsType::TEAMTAILOR, 'regex' => '#https?://([a-z0-9_-]+)\.teamtailor\.com#i'],
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
