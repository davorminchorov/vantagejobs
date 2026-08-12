<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
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
use RuntimeException;
use Tests\TestCase;

final class ScrapeOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ScrapeOrchestrator $orchestrator;

    private ScraperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->registry = $this->app->make(ScraperRegistry::class);
        $this->orchestrator = new ScrapeOrchestrator($this->registry);
    }

    #[Test]
    public function writes_a_completed_scrape_run_when_scraper_succeeds(): void
    {
        $this->registry->register($this->fakeScraper(
            AtsType::GREENHOUSE,
            result: new ScraperRunResult(fetched: 5, newCount: 2, closedCount: 1),
        ));

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        $run = $this->orchestrator->runForCompany($company);

        $this->assertSame('completed', $run->status);
        $this->assertSame(5, $run->fetched);
        $this->assertSame(2, $run->new_count);
        $this->assertSame(1, $run->closed_count);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->errors);
    }

    #[Test]
    public function records_not_modified_when_scraper_short_circuits(): void
    {
        $this->registry->register($this->fakeScraper(
            AtsType::LEVER,
            result: ScraperRunResult::notModified(etag: 'W/"abc"'),
        ));

        $company = Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'acme']);

        $run = $this->orchestrator->runForCompany($company);

        $this->assertSame('not_modified', $run->status);
        $this->assertSame(0, $run->fetched);
    }

    #[Test]
    public function records_partial_when_run_returns_errors(): void
    {
        $this->registry->register($this->fakeScraper(
            AtsType::ASHBY,
            result: new ScraperRunResult(fetched: 2, newCount: 0, closedCount: 0, errors: [['message' => 'parse glitch']]),
        ));

        $company = Company::factory()->create(['ats_type' => 'ashby', 'ats_slug' => 'acme']);

        $run = $this->orchestrator->runForCompany($company);

        $this->assertSame('partial', $run->status);
        $this->assertSame([['message' => 'parse glitch']], $run->errors);
    }

    #[Test]
    public function records_error_when_scraper_throws(): void
    {
        $this->registry->register($this->fakeScraper(
            AtsType::GREENHOUSE,
            throws: new RuntimeException('network down'),
        ));

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        $run = $this->orchestrator->runForCompany($company);

        $this->assertSame('error', $run->status);
        $this->assertSame('network down', $run->errors[0]['message']);
    }

    #[Test]
    public function records_error_when_no_scraper_is_registered_for_ats_type(): void
    {
        // No scraper registered for ASHBY in this test's registry slot
        // (we replace it with a noop just in case prior test left state):
        $registry = new ScraperRegistry();
        $orchestrator = new ScrapeOrchestrator($registry);

        $company = Company::factory()->create(['ats_type' => 'ashby', 'ats_slug' => 'acme']);

        $run = $orchestrator->runForCompany($company);

        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('No scraper registered', $run->errors[0]['message']);
    }

    #[Test]
    public function throws_when_company_has_no_ats_type(): void
    {
        $company = Company::factory()->create(['ats_type' => null, 'ats_slug' => 'acme']);

        $this->expectExceptionMessage('has no ats_type set');
        $this->orchestrator->runForCompany($company);
    }

    #[Test]
    public function throws_when_company_has_unknown_ats_type(): void
    {
        $company = Company::factory()->create(['ats_type' => 'bamboohr', 'ats_slug' => 'acme']);

        $this->expectExceptionMessage('unknown ats_type [bamboohr]');
        $this->orchestrator->runForCompany($company);
    }

    private function fakeScraper(AtsType $type, ?ScraperRunResult $result = null, ?\Throwable $throws = null): AtsScraperInterface
    {
        return new class($type, $result, $throws) implements AtsScraperInterface
        {
            public function __construct(
                private AtsType $type,
                private ?ScraperRunResult $result,
                private ?\Throwable $throws,
            ) {}

            public function name(): AtsType
            {
                return $this->type;
            }

            public function run(Company $company): ScraperRunResult
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->result ?? new ScraperRunResult(0, 0, 0);
            }
        };
    }
}
