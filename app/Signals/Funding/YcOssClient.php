<?php

declare(strict_types=1);

namespace App\Signals\Funding;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Thin client for the yc-oss YC Company Mirror.
 *
 * Static JSON files on GitHub Pages — no auth, no rate limit. We pull
 * the all.json blob once per sync run and let the caller diff it
 * against tracked companies. See SOURCES §4.6.
 */
final readonly class YcOssClient
{
    public function __construct(private HttpFactory $http) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $endpoint = 'https://yc-oss.github.io/api/companies/all.json'): array
    {
        $response = $this->http
            ->timeout(20)
            ->acceptJson()
            ->get($endpoint);

        if (! $response->successful()) {
            throw new \RuntimeException("yc-oss fetch failed: HTTP {$response->status()}");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('yc-oss response was not a JSON array');
        }

        /** @var list<array<string, mixed>> $payload */
        return $payload;
    }
}
