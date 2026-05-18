<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\HackerNews;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Algolia HackerNews comment record into a SourceContext.
 *
 * Pure — no HTTP, no DB. The HackerNews scraper (step 7) wraps this with the
 * fetch + persistence layer. Tested end-to-end via tests/Feature/Scrapers/
 * Aggregator/Parser/HackerNewsParserFixturesTest.php against real-shape
 * fixtures.
 *
 * The first line of a HackerNews "Who is Hiring" comment typically follows
 * SOURCES.md §2.1's convention:
 *
 *   Company | Role | Location | REMOTE | $range | tech stack | URL
 *
 * Tokens may be missing or reordered. We split by the first separator that
 * appears (`|`, ` – `, ` — `, ` - `), then walk the tokens classifying each
 * by shape:
 *
 *   t[0]   -> company_name
 *   t[1]   -> title
 *   t[2..] -> URL? salary? remote-marker? location? else stack tags
 */
final class HackerNewsContextBuilder
{
    /**
     * Separators in priority order. Pipe is overwhelmingly the convention;
     * the rest are fallbacks for posters who use dashes instead.
     *
     * @var list<string>
     */
    private const SEPARATORS = ['|', ' – ', ' — ', ' - '];

    /**
     * @param  array{id?: int|string, author?: string, text?: string, created_at?: string}  $comment
     */
    public function build(array $comment): SourceContext
    {
        $rawHtml = (string) ($comment['text'] ?? '');
        $bodyText = $this->stripHtml($rawHtml);
        $tokens = $this->splitFirstLine($bodyText);
        $structured = $this->classifyTokens($tokens);

        if (isset($structured['apply_url']) && ! isset($structured['url'])) {
            $structured['url'] = $structured['apply_url'];
        }

        $postedAt = null;
        $created = $comment['created_at'] ?? null;
        if (is_string($created) && $created !== '') {
            try {
                $postedAt = CarbonImmutable::parse($created);
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($comment['id']) ? (string) $comment['id'] : null;
        $contextUrl = $structured['apply_url'] ?? $this->firstUrl($bodyText);

        return new SourceContext(
            sourceType: AggregatorSourceType::HACKERNEWS,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $rawHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $contextUrl,
        );
    }

    private function stripHtml(string $html): string
    {
        // Decode entities first so e.g. &gt; and &mdash; survive into the
        // body and into our separator detection.
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Convert block-level breaks to newlines so a multi-paragraph posting
        // doesn't get mashed into one line.
        $decoded = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $decoded);
        $decoded = (string) preg_replace('/<\s*\/\s*p\s*>/i', "\n", $decoded);
        $decoded = (string) preg_replace('/<\s*p[^>]*>/i', '', $decoded);

        $stripped = strip_tags($decoded);
        $stripped = (string) preg_replace('/[ \t]+/', ' ', $stripped);
        $stripped = (string) preg_replace('/\n{3,}/', "\n\n", $stripped);

        return trim($stripped);
    }

    /**
     * @return list<string>
     */
    private function splitFirstLine(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $lines = (array) preg_split('/\R/', $body, 2);
        $first = trim((string) ($lines[0] ?? ''));
        if ($first === '') {
            return [];
        }

        foreach (self::SEPARATORS as $sep) {
            if (str_contains($first, $sep)) {
                $tokens = array_values(array_filter(array_map('trim', explode($sep, $first)), static fn (string $t): bool => $t !== ''));

                return $tokens;
            }
        }

        // No separator — return the whole line as one token so extractors
        // can fall back to body-text matching.
        return [$first];
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private function classifyTokens(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $structured = [
            'company_name' => $tokens[0],
            'title' => $tokens[1] ?? '',
        ];

        $tags = [];

        for ($i = 2; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if ($this->looksLikeUrl($token)) {
                $structured['apply_url'] = $token;

                continue;
            }

            if ($this->looksLikeSalary($token)) {
                $structured['salary'] = $token;

                continue;
            }

            if ($this->looksLikeRemoteMarker($token)) {
                if (! isset($structured['location'])) {
                    $structured['location'] = $token;
                }
                $structured['is_remote'] = true;

                continue;
            }

            if (! isset($structured['location'])) {
                $structured['location'] = $token;

                continue;
            }

            foreach ($this->splitStack($token) as $tag) {
                $tags[] = $tag;
            }
        }

        if ($tags !== []) {
            $structured['tags'] = array_values(array_unique($tags));
        }

        return $structured;
    }

    private function looksLikeUrl(string $token): bool
    {
        return (bool) preg_match('/https?:\/\//i', $token);
    }

    private function looksLikeSalary(string $token): bool
    {
        return (bool) preg_match('/[\$€£]|\b\d{2,3}k\b|\b\d{1,3}(?:,\d{3})+\b|\busd\b|\beur\b|\bgbp\b/i', $token);
    }

    private function looksLikeRemoteMarker(string $token): bool
    {
        return (bool) preg_match('/\b(remote|wfh|work\s+from\s+home|distributed|fully\s+remote|anywhere|onsite|on[\s\-]site|hybrid)\b/i', $token);
    }

    /**
     * Split a stack-tag-shaped token like "TypeScript, React, Postgres" or
     * "Go / Kubernetes / Kafka" into individual tokens.
     *
     * @return list<string>
     */
    private function splitStack(string $token): array
    {
        $parts = (array) preg_split('/[,\/]|\s+\&\s+|\s+\+\s+/u', $token);
        $out = [];
        foreach ($parts as $part) {
            if (! is_string($part)) {
                continue;
            }
            $clean = trim($part);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }

    private function firstUrl(string $body): ?string
    {
        if (preg_match('/https?:\/\/[^\s<>"\']+/i', $body, $m) === 1) {
            return $m[0];
        }

        return null;
    }
}
