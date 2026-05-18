<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Artifacts;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunArtifact;
use App\Scrapers\Aggregator\Artifacts\ScrapeArtifactContext;
use App\Scrapers\Aggregator\HackerNews\HackerNewsAlgoliaConnector;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\RemoteOk\RemoteOkConnector;
use App\Scrapers\Aggregator\RemoteOk\Requests\JobsRequest;
use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use App\Scrapers\Aggregator\WeWorkRemotely\Requests\CategoryFeedRequest;
use App\Scrapers\Aggregator\WeWorkRemotely\WeWorkRemotelyConnector;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class ArtifactWriteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private ScrapeArtifactContext $context;

    private FilesystemFactory $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()
            ->ofType(AggregatorSourceType::REMOTEOK)
            ->create();

        $this->context = $this->app->make(ScrapeArtifactContext::class);
        $this->filesystem = $this->app->make(FilesystemFactory::class);
    }

    #[Test]
    public function middleware_writes_gzipped_artifact_and_db_row_when_context_is_set(): void
    {
        $payload = [['legal' => 'x'], ['id' => 'rok-1', 'company' => 'A', 'position' => 'Eng']];

        Saloon::fake([
            JobsRequest::class => MockResponse::make($payload, 200, ['Content-Type' => 'application/json']),
        ]);

        $run = ScrapeRun::query()->create([
            'company_id' => null,
            'source' => 'remoteok',
            'source_kind' => 'aggregator',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
        ]);

        $this->context->set(
            scrapeRunId: (int) $run->id,
            tenantId: (int) $this->tenant->id,
            sourceKind: 'aggregator',
            sourceId: (int) $this->source->id,
        );

        (new RemoteOkConnector())->send(new JobsRequest());

        $this->assertSame(1, ScrapeRunArtifact::query()->count());

        $artifact = ScrapeRunArtifact::query()->firstOrFail();
        $this->assertSame((int) $run->id, $artifact->scrape_run_id);
        $this->assertSame((int) $this->tenant->id, $artifact->tenant_id);
        $this->assertSame('aggregator', $artifact->source_kind);
        $this->assertSame((int) $this->source->id, $artifact->source_id);
        $this->assertSame('remote_ok', $artifact->connector);
        $this->assertSame(200, $artifact->http_status);
        $this->assertGreaterThan(0, $artifact->size_bytes);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact->sha256);
        $this->assertStringEndsWith('.json.gz', $artifact->path);

        $disk = $this->filesystem->disk('local');
        $this->assertTrue($disk->exists($artifact->path));

        // gzip round-trip — the captured body decompresses back to the JSON we sent.
        $raw = $disk->get($artifact->path);
        $decoded = gzdecode($raw);
        $this->assertIsString($decoded);
        $this->assertSame(json_encode($payload), $decoded);

        $this->assertSame(hash('sha256', $raw), $artifact->sha256);
    }

    #[Test]
    public function orphan_artifact_is_written_when_context_unset(): void
    {
        Saloon::fake([
            FetchThreadRequest::class => MockResponse::make(['children' => []], 200, ['Content-Type' => 'application/json']),
        ]);

        // Context deliberately not set — middleware should still record.
        $this->context->clear();

        (new HackerNewsAlgoliaConnector())->send(new FetchThreadRequest('12345'));

        $artifact = ScrapeRunArtifact::query()->firstOrFail();
        $this->assertNull($artifact->scrape_run_id);
        $this->assertNull($artifact->source_id);
        $this->assertSame('hacker_news_algolia', $artifact->connector);
        $this->assertStringContainsString('/orphan_', $artifact->path);
    }

    #[Test]
    public function filesystem_failure_is_logged_and_does_not_throw(): void
    {
        // Build a middleware whose filesystem factory's `disk()` throws on use.
        $brokenDisk = Mockery::mock(Filesystem::class);
        $brokenDisk->shouldReceive('put')->andThrow(new RuntimeException('disk full'));

        $brokenFactory = Mockery::mock(FilesystemFactory::class);
        $brokenFactory->shouldReceive('disk')->with('local')->andReturn($brokenDisk);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(static fn (string $message, array $ctx): bool => str_contains($message, 'scrape-artifact'));

        $middleware = new RecordResponseArtifact($brokenFactory, $logger, $this->context);

        $this->app->instance(RecordResponseArtifact::class, $middleware);

        Saloon::fake([
            JobsRequest::class => MockResponse::make(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = (new RemoteOkConnector())->send(new JobsRequest());

        // The request itself must succeed even though the artifact write blew up.
        $this->assertSame(200, $response->status());
        $this->assertSame(0, ScrapeRunArtifact::query()->count());
    }

    #[Test]
    public function content_type_drives_file_extension(): void
    {
        Saloon::fake([
            CategoryFeedRequest::class => MockResponse::make(
                '<rss><channel></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);

        (new WeWorkRemotelyConnector())->send(new CategoryFeedRequest('backend-jobs'));

        $artifact = ScrapeRunArtifact::query()->firstOrFail();
        $this->assertStringEndsWith('.bin.gz', $artifact->path);
        // application/rss+xml doesn't contain "application/xml" or "text/xml" — falls through to .bin
        // per the explicit allowlist in the middleware. Documents the expectation.
    }
}
