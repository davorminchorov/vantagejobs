<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews;

/**
 * Spots job seekers who post in the "Who is Hiring" thread instead of the
 * "Who wants to be hired?" one. Two shapes, both deliberately conservative
 * because a false positive hides a real job (listings flagged here are
 * auto-dismissed, which stays recoverable from the Auto-dismissed tab):
 *
 *   1. The "Who wants to be hired?" template: "Willing to relocate:" plus at
 *      least two more of its labels (Location:, Remote:, Technologies:,
 *      Résumé/Resume/CV:, Email:). Job postings don't use "Willing to
 *      relocate:" as a field label.
 *   2. A self-introduction opening: "I'm Jane Doe, a full-stack engineer…" /
 *      "My name is …", unless the comment also talks about hiring.
 *
 * Pure: works on the stripped body text from HackerNewsContextBuilder.
 */
final class JobSeekerDetector
{
    public const REASON = 'job_seeker';

    private const TEMPLATE_LABELS = [
        'location',
        'remote',
        'technologies',
        'r[ée]sum[ée]',
        'cv',
        'email',
    ];

    public function looksLikeJobSeeker(string $bodyText): bool
    {
        return $this->usesSeekerTemplate($bodyText) || $this->opensWithSelfIntroduction($bodyText);
    }

    private function usesSeekerTemplate(string $body): bool
    {
        if (preg_match('/\bwilling\s+to\s+relocate\s*:/iu', $body) !== 1) {
            return false;
        }

        $labels = 0;
        foreach (self::TEMPLATE_LABELS as $label) {
            if (preg_match('/(?:^|[\s|])'.$label.'\s*:/imu', $body) === 1) {
                $labels++;
            }
        }

        return $labels >= 2;
    }

    private function opensWithSelfIntroduction(string $body): bool
    {
        $opening = mb_substr(ltrim($body), 0, 200);

        // "I'm Jane Doe," / "Hi, I am Jane," / "My name is Jane Doe." -- a
        // capitalised name right after the intro phrase. "I'm the CTO at…"
        // or "I'm hiring…" don't match because "the"/"hiring" aren't names.
        $introducesSelf = preg_match(
            "/^(?i:(?:hi|hello|hey)\\b[\\s,!.]*)?(?i:i['’]m|i\\s+am|my\\s+name\\s+is)\\s+\\p{Lu}[\\p{L}'’-]+(?:\\s+\\p{Lu}[\\p{L}'’-]+){0,2}\\s*[,.:;–—-]/u",
            $opening,
        ) === 1;

        if (! $introducesSelf) {
            return false;
        }

        return preg_match('/\b(?:hiring|we[\'’]re\s+looking|we\s+are\s+looking|join\s+(?:us|our)|our\s+team)\b/iu', $body) !== 1;
    }
}
