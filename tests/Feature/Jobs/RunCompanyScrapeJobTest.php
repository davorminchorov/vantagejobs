<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AtsType;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScrapeOrchestrator;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\ScraperRunResult;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunCompanyScrapeJobTest extends TestCase
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
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 4, newCount: 2, closedCount: 0));
        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        // Simulate the worker: context is empty until the job sets it.
        app(TenantContext::class)->setCurrent(null);

        (new RunCompanyScrapeJob($this->tenant->id, $company->id))
            ->handle(app(TenantContext::class), app(ScrapeOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(1, ScrapeRun::query()->count());
        $run = ScrapeRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->new_count);
        $this->assertSame($this->tenant->id, (int) $run->tenant_id);
    }

    #[Test]
    public function it_is_a_safe_no_op_when_the_company_is_missing(): void
    {
        app(TenantContext::class)->setCurrent(null);

        (new RunCompanyScrapeJob($this->tenant->id, 999999))
            ->handle(app(TenantContext::class), app(ScrapeOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(0, ScrapeRun::query()->count());
    }

    private function fakeScraper(AtsType $type, ScraperRunResult $result): void
    {
        /** @var ScraperRegistry $registry */
        $registry = $this->app->make(ScraperRegistry::class);
        $registry->register(new class($type, $result) implements AtsScraperInterface
        {
            public function __construct(private AtsType $type, private ScraperRunResult $result) {}

            public function name(): AtsType
            {
                return $this->type;
            }

            public function run(Company $company): ScraperRunResult
            {
                return $this->result;
            }
        });
    }
}
