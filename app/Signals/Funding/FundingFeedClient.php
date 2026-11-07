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

    private const SUMMARY_LIMIT = 280;

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, summary: string|null, categories: list<string>}>
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

            $categories = [];
            foreach ($item->category as $category) {
                $value = trim(html_entity_decode((string) $category, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($value !== '' && ! in_array($value, $categories, true)) {
                    $categories[] = $value;
                }
            }

            $items[] = [
                'external_id' => $externalId,
                'title' => $title,
                'url' => $link,
                'published_at' => $this->date(trim((string) ($item->pubDate ?? ''))),
                'summary' => $this->summary((string) ($item->description ?? '')),
                'categories' => $categories,
            ];
        }

        return $items;
    }

    /**
     * Plain-text first sentence or two of the item description, without the
     * images and "The post ... appeared first on ..." footers WordPress adds.
     */
    private function summary(string $html): ?string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s*The post .* appeared first on .*$/u', '', $text));
        $text = trim((string) preg_replace('/\s*(\[(…|\.\.\.)\]|Continue reading.*)$/u', '', $text));

        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= self::SUMMARY_LIMIT) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::SUMMARY_LIMIT);
        $sentenceEnd = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));

        return $sentenceEnd > 80
            ? mb_substr($cut, 0, $sentenceEnd + 1)
            : rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' '))).'…';
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
