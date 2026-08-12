<?php

declare(strict_types=1);

namespace App\Scrapers\Support;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Request;

/**
 * Base connector wiring config('scrapers.{configKey}.retry_times'/
 * 'retry_sleep_ms') into Saloon's native retry mechanism.
 *
 * Setting $tries > 1 makes Saloon's send() call $response->throw() after
 * every attempt (see Saloon\Traits\Connector\SendsRequests::send()), which by
 * default throws a RequestException for any 4xx/5xx response. $throwOnMaxTries
 * is forced to false, so once retries are exhausted send() returns that last
 * Response instead of throwing - every scraper's existing manual
 * `$response->status() >= 400` handling keeps working completely unchanged;
 * retries just happen transparently underneath it.
 *
 * Subclasses that need their own constructor (per-company base URLs, DI'd
 * config repositories, etc.) call applyRetryConfig() themselves; subclasses
 * with no other constructor needs get it for free from this class's own
 * __construct().
 */
abstract class ConfigurableConnector extends Connector
{
    public function __construct()
    {
        $this->applyRetryConfig($this->configKey());
    }

    /**
     * Dot-path under config('scrapers.') holding this source's timeout/retry
     * settings, e.g. "sources.greenhouse" or "aggregators.remotive".
     */
    abstract protected function configKey(): string;

    final protected function applyRetryConfig(string $configKey): void
    {
        $this->tries = max(1, (int) config("scrapers.{$configKey}.retry_times", 1));
        $this->retryInterval = max(0, (int) config("scrapers.{$configKey}.retry_sleep_ms", 0));
        $this->throwOnMaxTries = false;
    }

    /**
     * Skip retrying "definitive" 4xx responses - a 404 board will still be a
     * 404 next attempt. Retry 429 (rate limited), 5xx, and connection-level
     * failures (timeouts, DNS, refused connections - these arrive as
     * FatalRequestException, which carries no response to inspect).
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        $status = $exception->getResponse()->status();

        return $status === 429 || $status >= 500;
    }
}
