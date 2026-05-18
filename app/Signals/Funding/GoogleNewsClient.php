<?php

declare(strict_types=1);

namespace App\Signals\Funding;

use App\Models\FundingCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Search Google News RSS for funding-related articles about a specific
 * company. Each returned item is a CANDIDATE funding event — never
 * applied to Company funding scalars automatically. Promotion happens
 * through the review UI (see Insights/FundingCandidates).
 *
 * Endpoint: https://news.google.com/rss/search?q={query}&hl=en-US&gl=US&ceid=US:en
 *
 * Off-topic articles are dropped: an item is only emitted if its title
 * matches at least one preset funding keyword (case-insensitive).
 *
 * Output shape per item:
 *   [
 *     'external_id'  => string,            // <guid> or <link>
 *     'title'        => string,
 *     'url'          => string,
 *     'published_at' => CarbonImmutable|null,
 *     'matched'      => list<string>,       // preset keywords found in title
 *   ]
 */
final class GoogleNewsClient
{
    private const TIMEOUT_SECONDS = 10;

    private const USER_AGENT = 'VantageJobs-FundingPoller/1.0 (+https://vantagejobs.local)';

    private const ENDPOINT = 'https://news.google.com/rss/search';

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, matched: list<string>}>
     */
    public function search(string $companyName): array
    {
        $query = '"'.$companyName.'" raised OR Series OR funding';

        $url = self::ENDPOINT.'?'.http_build_query([
            'q' => $query,
            'hl' => 'en-US',
            'gl' => 'US',
            'ceid' => 'US:en',
        ]);

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException $e) {
            throw FundingFetchException::transport($url, $e->getMessage());
        } catch (Throwable $e) {
            throw FundingFetchException::transport($url, $e->getMessage());
        }

        if (! $response->successful()) {
            throw FundingFetchException::transport($url, 'HTTP '.$response->status());
        }

        $body = trim($response->body());
        if ($body === '') {
            return [];
        }

        return $this->parse($url, $body);
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, matched: list<string>}>
     */
    private function parse(string $url, string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
        } catch (Throwable $e) {
            libxml_use_internal_errors($previous);
            throw FundingFetchException::unparseable($url, $e->getMessage());
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement || ! isset($xml->channel)) {
            throw FundingFetchException::unparseable($url, 'invalid RSS structure');
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            $link = trim((string) ($item->link ?? ''));
            $guid = trim((string) ($item->guid ?? ''));
            $externalId = $guid !== '' ? $guid : $link;
            if ($externalId === '') {
                continue;
            }

            $title = trim((string) ($item->title ?? ''));
            if ($title === '') {
                continue;
            }

            $matched = $this->matchKeywords($title);
            if ($matched === []) {
                // Off-topic: title doesn't mention any preset funding term.
                continue;
            }

            $publishedAt = $this->parseDate(trim((string) ($item->pubDate ?? '')));

            $items[] = [
                'external_id' => $externalId,
                'title' => $title,
                'url' => $link !== '' ? $link : $externalId,
                'published_at' => $publishedAt,
                'matched' => $matched,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function matchKeywords(string $title): array
    {
        $haystack = mb_strtolower($title);
        $matched = [];
        foreach (FundingCandidate::PRESET_KEYWORDS as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                $matched[] = $keyword;
            }
        }

        return $matched;
    }

    private function parseDate(string $raw): ?CarbonImmutable
    {
        if ($raw === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
