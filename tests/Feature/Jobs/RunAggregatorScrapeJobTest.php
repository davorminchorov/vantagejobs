<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AggregatorSourceType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunAggregatorScrapeJobTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function it_runs_the_orchestrator_and_records_a_scrape_run(): void
    {
        $this->fakeScraper(AggregatorSourceType::REMOTIVE, new AggregatorRunResult(fetched: 6, newCount: 3));
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        app(TenantContext::class)->setCurrent(null);

        (new RunAggregatorScrapeJob($this->tenant->id, $source->id))
            ->handle(app(TenantContext::class), app(AggregatorOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(1, ScrapeRun::query()->count());
        $run = ScrapeRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame('aggregator', $run->source_kind);
        $this->assertSame(3, $run->new_count);
    }

    #[Test]
    public function it_is_a_safe_no_op_when_the_source_is_missing(): void
    {
        app(TenantContext::class)->setCurrent(null);

        (new RunAggregatorScrapeJob($this->tenant->id, 999999))
            ->handle(app(TenantContext::class), app(AggregatorOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(0, ScrapeRun::query()->count());
    }

    private function fakeScraper(AggregatorSourceType $type, AggregatorRunResult $result): void
    {
        /** @var AggregatorRegistry $registry */
        $registry = $this->app->make(AggregatorRegistry::class);
        $registry->register(new class($type, $result) implements AggregatorScraperInterface
        {
            public function __construct(private AggregatorSourceType $type, private AggregatorRunResult $result) {}

            public function name(): AggregatorSourceType
            {
                return $this->type;
            }

            public function run(AggregatorSource $source): AggregatorRunResult
            {
                return $this->result;
            }
        });
    }
}
