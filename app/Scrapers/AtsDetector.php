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
        // Pinpoint: {slug}.pinpointhq.com
        ['type' => AtsType::PINPOINT, 'regex' => '#https?://([a-z0-9_-]+)\.pinpointhq\.com#i'],
        // BambooHR: {slug}.bamboohr.com. www is the marketing site, never a
        // board (invalid slugs 302-redirect there), so it's excluded.
        ['type' => AtsType::BAMBOOHR, 'regex' => '#https?://(?!www\.)([a-z0-9_-]+)\.bamboohr\.com#i'],
        // Rippling: the API form must precede the generic path catch-all so
        // "api" is never mistaken for a board slug.
        ['type' => AtsType::RIPPLING, 'regex' => '#ats\.rippling\.com/api/v[0-9]+/board/([a-z0-9_-]+)#i'],
        ['type' => AtsType::RIPPLING, 'regex' => '#ats\.rippling\.com/([a-z0-9_-]+)#i'],
        // Gem: jobs.gem.com/{slug} (the API lives on api.gem.com but careers
        // links always point at the board host).
        ['type' => AtsType::GEM, 'regex' => '#jobs\.gem\.com/([a-z0-9_-]+)#i'],
        ['type' => AtsType::GEM, 'regex' => '#api\.gem\.com/job_board/v[0-9]+/([a-z0-9_-]+)#i'],
    ];

    /**
     * Workday identity is two-part (tenant + site) and additionally carries a
     * numbered pod host that is not derivable from either, so it bypasses the
     * single-capture PATTERNS table: ats_slug is packed as "tenant/site" and
     * the pod host is returned as ats_host so the scraper can skip probing.
     * The site segment is case-sensitive on Workday's side and is preserved
     * as-is; the CXS API form must be tried first so "wday" is never
     * mistaken for a site name.
     *
     * @var list<string>
     */
    private const WORKDAY_PATTERNS = [
        '#https?://([a-z0-9_-]+)\.(wd[0-9]+)\.myworkdayjobs\.com/wday/cxs/[a-z0-9_-]+/([A-Za-z0-9_-]+)#i',
        '#https?://([a-z0-9_-]+)\.(wd[0-9]+)\.myworkdayjobs\.com/(?:[a-z]{2}-[A-Z]{2}/)?([A-Za-z0-9_-]+)#i',
    ];

    /**
     * @return ?array{ats_type: string, ats_slug: string, ats_host?: string}
     */
    public function detect(?string $url): ?array
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        foreach (self::WORKDAY_PATTERNS as $regex) {
            if (preg_match($regex, $url, $matches) === 1) {
                $tenant = mb_strtolower($matches[1]);

                return [
                    'ats_type' => AtsType::WORKDAY->value,
                    'ats_slug' => "{$tenant}/{$matches[3]}",
                    'ats_host' => $tenant.'.'.mb_strtolower($matches[2]).'.myworkdayjobs.com',
                ];
            }
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
