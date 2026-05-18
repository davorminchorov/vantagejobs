<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class AggregatorOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorOrchestrator $orchestrator;

    private AggregatorRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->registry = $this->app->make(AggregatorRegistry::class);
        $this->orchestrator = $this->app->make(AggregatorOrchestrator::class);
    }

    #[Test]
    public function writes_a_completed_aggregator_run_when_scraper_succeeds(): void
    {
        $this->registry->register($this->fakeScraper(
            AggregatorSourceType::HACKERNEWS,
            result: new AggregatorRunResult(fetched: 312, newCount: 87),
        ));

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        $run = $this->orchestrator->runForSource($source);

        $this->assertSame('completed', $run->status);
        $this->assertSame('aggregator', $run->source_kind);
        $this->assertSame('hackernews', $run->source);
        $this->assertNull($run->company_id);
        $this->assertSame(312, $run->fetched);
        $this->assertSame(87, $run->new_count);
        $this->assertSame(0, $run->closed_count);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->errors);
    }

    #[Test]
    public function records_partial_when_run_returns_errors(): void
    {
        $this->registry->register($this->fakeScraper(
            AggregatorSourceType::REMOTIVE,
            result: new AggregatorRunResult(
                fetched: 100,
                newCount: 12,
                errors: [['message' => '3 listings failed to parse']],
            ),
        ));

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        $run = $this->orchestrator->runForSource($source);

        $this->assertSame('partial', $run->status);
        $this->assertSame([['message' => '3 listings failed to parse']], $run->errors);
    }

    #[Test]
    public function records_error_when_scraper_throws(): void
    {
        $this->registry->register($this->fakeScraper(
            AggregatorSourceType::WEWORKREMOTELY,
            throws: new RuntimeException('rss feed 503'),
        ));

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::WEWORKREMOTELY)->create();

        $run = $this->orchestrator->runForSource($source);

        $this->assertSame('error', $run->status);
        $this->assertSame('rss feed 503', $run->errors[0]['message']);
    }

    #[Test]
    public function records_error_when_no_scraper_is_registered_for_source_type(): void
    {
        $this->app->instance(AggregatorRegistry::class, new AggregatorRegistry());
        $orchestrator = $this->app->make(AggregatorOrchestrator::class);

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create();

        $run = $orchestrator->runForSource($source);

        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('No aggregator scraper registered', $run->errors[0]['message']);
    }

    #[Test]
    public function success_updates_source_run_state_and_clears_errors(): void
    {
        $this->registry->register($this->fakeScraper(
            AggregatorSourceType::REMOTEOK,
            result: new AggregatorRunResult(fetched: 50, newCount: 5),
        ));

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create([
            'last_error' => 'prior failure',
            'consecutive_failures' => 4,
        ]);

        $this->orchestrator->runForSource($source);
        $source->refresh();

        $this->assertNotNull($source->last_run_at);
        $this->assertNotNull($source->next_run_at);
        $this->assertNull($source->last_error);
        $this->assertSame(0, $source->consecutive_failures);
        $this->assertTrue($source->next_run_at->greaterThan($source->last_run_at));
    }

    #[Test]
    public function failure_increments_consecutive_failures_and_stores_last_error(): void
    {
        $this->registry->register($this->fakeScraper(
            AggregatorSourceType::HIMALAYAS,
            throws: new RuntimeException('429 too many requests'),
        ));

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create([
            'consecutive_failures' => 1,
        ]);

        $this->orchestrator->runForSource($source);
        $source->refresh();

        $this->assertSame(2, $source->consecutive_failures);
        $this->assertSame('429 too many requests', $source->last_error);
        $this->assertNotNull($source->last_run_at);
        $this->assertNotNull($source->next_run_at);
    }

    #[Test]
    public function missing_registration_also_counts_as_a_failure_on_the_source(): void
    {
        $this->app->instance(AggregatorRegistry::class, new AggregatorRegistry());
        $orchestrator = $this->app->make(AggregatorOrchestrator::class);

        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        $orchestrator->runForSource($source);
        $source->refresh();

        $this->assertSame(1, $source->consecutive_failures);
        $this->assertStringContainsString('No aggregator scraper registered', (string) $source->last_error);
    }

    private function fakeScraper(
        AggregatorSourceType $type,
        ?AggregatorRunResult $result = null,
        ?Throwable $throws = null,
    ): AggregatorScraperInterface {
        return new class($type, $result, $throws) implements AggregatorScraperInterface
        {
            public function __construct(
                private AggregatorSourceType $type,
                private ?AggregatorRunResult $result,
                private ?Throwable $throws,
            ) {}

            public function name(): AggregatorSourceType
            {
                return $this->type;
            }

            public function concurrency(): int
            {
                return 1;
            }

            public function run(AggregatorSource $source): AggregatorRunResult
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->result ?? new AggregatorRunResult(0, 0);
            }
        };
    }
}
