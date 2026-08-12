<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Scrapers\Aggregator\Remotive\RemotiveConnector;
use App\Scrapers\Aggregator\Remotive\Requests\RemoteJobsRequest;
use App\Scrapers\Greenhouse\GreenhouseConnector;
use App\Scrapers\Greenhouse\Requests\BoardJobsRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

/**
 * Exercises App\Scrapers\Support\ConfigurableConnector's retry wiring
 * directly against the Saloon send() pipeline - one ATS connector and one
 * aggregator connector, standing in for all 23 connectors that extend it.
 *
 * $tries/$retryInterval come from config('scrapers.{source}.retry_times'/
 * 'retry_sleep_ms'); handleRetry() decides which failures are worth a second
 * attempt.
 */
final class ConnectorRetryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ats_connector_retries_a_transient_500_then_succeeds(): void
    {
        config(['scrapers.sources.greenhouse.retry_times' => 3, 'scrapers.sources.greenhouse.retry_sleep_ms' => 0]);

        $attempts = 0;
        Saloon::fake([
            BoardJobsRequest::class => function (PendingRequest $request) use (&$attempts): MockResponse {
                $attempts++;

                return $attempts === 1
                    ? MockResponse::make(body: 'Service Unavailable', status: 500)
                    : MockResponse::make(body: ['jobs' => []], status: 200);
            },
        ]);

        $response = (new GreenhouseConnector())->send(new BoardJobsRequest(boardToken: 'acme'));

        $this->assertSame(2, $attempts);
        $this->assertSame(200, $response->status());
    }

    #[Test]
    public function ats_connector_does_not_retry_a_definitive_404(): void
    {
        config(['scrapers.sources.greenhouse.retry_times' => 3, 'scrapers.sources.greenhouse.retry_sleep_ms' => 0]);

        $attempts = 0;
        Saloon::fake([
            BoardJobsRequest::class => function (PendingRequest $request) use (&$attempts): MockResponse {
                $attempts++;

                return MockResponse::make(body: 'Not Found', status: 404);
            },
        ]);

        $response = (new GreenhouseConnector())->send(new BoardJobsRequest(boardToken: 'ghost'));

        $this->assertSame(1, $attempts);
        $this->assertSame(404, $response->status());
    }

    #[Test]
    public function aggregator_connector_retries_a_429_then_succeeds(): void
    {
        config(['scrapers.aggregators.remotive.retry_times' => 3, 'scrapers.aggregators.remotive.retry_sleep_ms' => 0]);

        $attempts = 0;
        Saloon::fake([
            RemoteJobsRequest::class => function (PendingRequest $request) use (&$attempts): MockResponse {
                $attempts++;

                return $attempts === 1
                    ? MockResponse::make(body: 'Too Many Requests', status: 429)
                    : MockResponse::make(body: [], status: 200);
            },
        ]);

        $response = (new RemotiveConnector())->send(new RemoteJobsRequest());

        $this->assertSame(2, $attempts);
        $this->assertSame(200, $response->status());
    }

    #[Test]
    public function aggregator_connector_exhausts_retries_and_returns_the_last_response(): void
    {
        config(['scrapers.aggregators.remotive.retry_times' => 2, 'scrapers.aggregators.remotive.retry_sleep_ms' => 0]);

        $attempts = 0;
        Saloon::fake([
            RemoteJobsRequest::class => function (PendingRequest $request) use (&$attempts): MockResponse {
                $attempts++;

                return MockResponse::make(body: 'Service Unavailable', status: 503);
            },
        ]);

        $response = (new RemotiveConnector())->send(new RemoteJobsRequest());

        $this->assertSame(2, $attempts);
        // throwOnMaxTries = false: the caller's own status()>=400 handling
        // still runs on this response instead of an exception propagating.
        $this->assertSame(503, $response->status());
    }
}
