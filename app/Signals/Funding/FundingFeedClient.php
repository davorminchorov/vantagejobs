<?php

declare(strict_types=1);

namespace App\Signals\Funding;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Reads one startup-news RSS feed (FundingFeedSource) and returns its items.
 * Deciding which items are funding news happens in signals:funding:feed.
 */
final class FundingFeedClient
{
    private const TIMEOUT_SECONDS = 15;

    private const USER_AGENT = 'VantageJobs-FundingFeed/1.0 (+https://vantagejobs.local)';

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null}>
     */
    public function fetch(FundingFeedSource $source): array
    {
        $url = $source->url();

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
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

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement || ! isset($xml->channel)) {
            throw FundingFetchException::unparseable($url, 'invalid RSS structure');
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            $link = trim((string) ($item->link ?? ''));
            $guid = trim((string) ($item->guid ?? ''));
            $title = trim(html_entity_decode((string) ($item->title ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $externalId = $guid !== '' ? $guid : $link;

            if ($externalId === '' || $title === '' || $link === '') {
                continue;
            }

            $items[] = [
                'external_id' => $externalId,
                'title' => $title,
                'url' => $link,
                'published_at' => $this->date(trim((string) ($item->pubDate ?? ''))),
            ];
        }

        return $items;
    }

    private function date(string $raw): ?CarbonImmutable
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
