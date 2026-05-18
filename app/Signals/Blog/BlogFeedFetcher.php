<?php

declare(strict_types=1);

namespace App\Signals\Blog;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Fetches and normalises a company engineering-blog feed (RSS 2.0 or Atom 1.0).
 *
 * Output items are shaped uniformly regardless of feed flavour:
 *   [
 *     'external_id'  => string,            // guid / id / link
 *     'title'        => string,
 *     'url'          => string,
 *     'published_at' => CarbonImmutable|null,
 *     'excerpt'      => string|null,
 *   ]
 *
 * Plain Http facade with a 10s timeout — feeds are tiny and we never need
 * the full Saloon ceremony here.
 */
final class BlogFeedFetcher
{
    private const TIMEOUT_SECONDS = 10;

    private const USER_AGENT = 'VantageJobs-BlogPoller/1.0 (+https://vantagejobs.local)';

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, excerpt: string|null}>
     */
    public function fetch(string $url): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException $e) {
            throw FeedFetchException::transport($url, $e->getMessage());
        } catch (Throwable $e) {
            throw FeedFetchException::transport($url, $e->getMessage());
        }

        if (! $response->successful()) {
            throw FeedFetchException::transport($url, 'HTTP '.$response->status());
        }

        $body = trim($response->body());
        if ($body === '') {
            throw FeedFetchException::unparseable($url, 'empty body');
        }

        return $this->parse($url, $body);
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, excerpt: string|null}>
     */
    private function parse(string $url, string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
        } catch (Throwable $e) {
            libxml_use_internal_errors($previous);
            throw FeedFetchException::unparseable($url, $e->getMessage());
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw FeedFetchException::unparseable($url, 'invalid XML');
        }

        $name = strtolower($xml->getName());

        if ($name === 'rss' && isset($xml->channel)) {
            return $this->parseRss($xml->channel);
        }

        if ($name === 'feed') {
            return $this->parseAtom($xml);
        }

        throw FeedFetchException::unparseable($url, "unrecognised root element [{$name}]");
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, excerpt: string|null}>
     */
    private function parseRss(SimpleXMLElement $channel): array
    {
        $items = [];
        foreach ($channel->item as $item) {
            $link = trim((string) ($item->link ?? ''));
            $guid = trim((string) ($item->guid ?? ''));
            $externalId = $guid !== '' ? $guid : $link;
            if ($externalId === '') {
                continue;
            }

            $title = trim((string) ($item->title ?? ''));
            if ($title === '') {
                $title = '(untitled)';
            }

            $pubDate = trim((string) ($item->pubDate ?? ''));
            $publishedAt = $this->parseDate($pubDate);

            $description = trim((string) ($item->description ?? ''));
            $excerpt = $description !== '' ? $this->squashExcerpt($description) : null;

            $items[] = [
                'external_id' => $externalId,
                'title' => $title,
                'url' => $link !== '' ? $link : $externalId,
                'published_at' => $publishedAt,
                'excerpt' => $excerpt,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, excerpt: string|null}>
     */
    private function parseAtom(SimpleXMLElement $feed): array
    {
        $items = [];
        foreach ($feed->entry as $entry) {
            $id = trim((string) ($entry->id ?? ''));

            // Atom link can be an attribute on a <link> sibling; prefer rel=alternate
            $link = $this->atomEntryLink($entry);

            $externalId = $id !== '' ? $id : $link;
            if ($externalId === '') {
                continue;
            }

            $title = trim((string) ($entry->title ?? ''));
            if ($title === '') {
                $title = '(untitled)';
            }

            $published = trim((string) ($entry->published ?? ''));
            if ($published === '') {
                $published = trim((string) ($entry->updated ?? ''));
            }
            $publishedAt = $this->parseDate($published);

            $summary = trim((string) ($entry->summary ?? ''));
            if ($summary === '') {
                $summary = trim((string) ($entry->content ?? ''));
            }
            $excerpt = $summary !== '' ? $this->squashExcerpt($summary) : null;

            $items[] = [
                'external_id' => $externalId,
                'title' => $title,
                'url' => $link !== '' ? $link : $externalId,
                'published_at' => $publishedAt,
                'excerpt' => $excerpt,
            ];
        }

        return $items;
    }

    private function atomEntryLink(SimpleXMLElement $entry): string
    {
        $fallback = '';
        foreach ($entry->link as $link) {
            $attrs = $link->attributes();
            $href = isset($attrs['href']) ? (string) $attrs['href'] : '';
            if ($href === '') {
                continue;
            }
            $rel = isset($attrs['rel']) ? (string) $attrs['rel'] : 'alternate';
            if ($rel === 'alternate') {
                return $href;
            }
            if ($fallback === '') {
                $fallback = $href;
            }
        }

        return $fallback;
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

    private function squashExcerpt(string $value): string
    {
        $stripped = trim(strip_tags($value));
        if ($stripped === '') {
            return '';
        }

        // Keep excerpts bounded so an over-eager feed doesn't blow up storage.
        if (mb_strlen($stripped) <= 500) {
            return $stripped;
        }

        return mb_substr($stripped, 0, 497).'...';
    }
}
