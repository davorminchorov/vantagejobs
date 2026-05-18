<?php

declare(strict_types=1);

namespace App\Signals\Github;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a GitHub org and computes a 0-100 "eng activity" score.
 *
 * Hits two REST endpoints per SOURCES §4.3:
 *   - GET /orgs/{org}                              (public_repos, created_at)
 *   - GET /orgs/{org}/repos?sort=pushed&per_page=10 (recency window)
 *
 * Composite score formula (documented for PRD §6.3.3):
 *
 *   base       = min(40, log10(max(1, public_repos)) * 20)   // org size, capped
 *   activity   = min(40, pushed_repos_90d * 5)               // 8 active repos = max
 *   innovation = min(20, new_repos_90d * 4)                  // 5 new repos = max
 *   score      = round(base + activity + innovation)         // 0..100
 *
 * "pushed_repos_90d" counts repos in the top-10 sorted-by-pushed page whose
 * pushed_at is within the last 90 days. "new_repos_90d" counts repos in the
 * same page whose created_at is within the last 90 days.
 *
 * Throws GithubFetchException on 404, 403, network timeout, or any
 * non-2xx response so callers can isolate per-org failures.
 */
final class GithubOrgScorer
{
    private const string API_BASE = 'https://api.github.com';

    private const int TIMEOUT_SECONDS = 5;

    private const int REPOS_PAGE_SIZE = 10;

    /**
     * @return array{score: int, public_repos: int, pushed_repos_90d: int, new_repos_90d: int, fetched_at: CarbonImmutable}
     */
    public function score(string $org): array
    {
        $token = (string) env('GITHUB_TOKEN', '');

        $client = Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['User-Agent' => 'vantagejobs-signals'])
            ->withOptions(['http_errors' => false]);

        if ($token !== '') {
            $client = $client->withToken($token);
        }

        try {
            $orgResponse = $client->get(self::API_BASE.'/orgs/'.$org);
        } catch (ConnectionException $e) {
            throw new GithubFetchException("Timeout fetching org [{$org}]: ".$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new GithubFetchException("Failed fetching org [{$org}]: ".$e->getMessage(), 0, $e);
        }

        if (! $orgResponse->successful()) {
            throw new GithubFetchException("GitHub org [{$org}] returned HTTP {$orgResponse->status()}");
        }

        /** @var array<string, mixed> $orgPayload */
        $orgPayload = (array) $orgResponse->json();
        $publicRepos = (int) ($orgPayload['public_repos'] ?? 0);

        try {
            $reposResponse = $client->get(self::API_BASE.'/orgs/'.$org.'/repos', [
                'sort' => 'pushed',
                'direction' => 'desc',
                'per_page' => self::REPOS_PAGE_SIZE,
            ]);
        } catch (ConnectionException $e) {
            throw new GithubFetchException("Timeout fetching repos for [{$org}]: ".$e->getMessage(), 0, $e);
        } catch (RequestException|Throwable $e) {
            throw new GithubFetchException("Failed fetching repos for [{$org}]: ".$e->getMessage(), 0, $e);
        }

        if (! $reposResponse->successful()) {
            throw new GithubFetchException("GitHub repos for [{$org}] returned HTTP {$reposResponse->status()}");
        }

        /** @var array<int, array<string, mixed>> $repos */
        $repos = (array) $reposResponse->json();

        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subDays(90);

        $pushedRepos90d = 0;
        $newRepos90d = 0;

        foreach ($repos as $repo) {
            $pushedAt = isset($repo['pushed_at']) && is_string($repo['pushed_at'])
                ? CarbonImmutable::parse($repo['pushed_at'])
                : null;
            $createdAt = isset($repo['created_at']) && is_string($repo['created_at'])
                ? CarbonImmutable::parse($repo['created_at'])
                : null;

            if ($pushedAt !== null && $pushedAt->greaterThanOrEqualTo($cutoff)) {
                $pushedRepos90d++;
            }
            if ($createdAt !== null && $createdAt->greaterThanOrEqualTo($cutoff)) {
                $newRepos90d++;
            }
        }

        $base = min(40.0, log10(max(1, $publicRepos)) * 20.0);
        $activity = min(40.0, $pushedRepos90d * 5.0);
        $innovation = min(20.0, $newRepos90d * 4.0);
        $score = (int) round($base + $activity + $innovation);

        if ($score < 0) {
            $score = 0;
        }
        if ($score > 100) {
            $score = 100;
        }

        return [
            'score' => $score,
            'public_repos' => $publicRepos,
            'pushed_repos_90d' => $pushedRepos90d,
            'new_repos_90d' => $newRepos90d,
            'fetched_at' => $now,
        ];
    }
}
