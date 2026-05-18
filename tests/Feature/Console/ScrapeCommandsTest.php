<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AtsType;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\ScraperRunResult;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ScrapeCommandsTest extends TestCase
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
    public function scrape_company_runs_one_company_and_emits_json(): void
    {
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 3, newCount: 1, closedCount: 0));
        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:company', ['company' => $company->id, '--tenant' => 'primary'])
            ->assertExitCode(0);

        $this->assertSame(1, ScrapeRun::query()->count());
        $run = ScrapeRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->new_count);
    }

    #[Test]
    public function scrape_company_fails_without_tenant_option(): void
    {
        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:company', ['company' => $company->id])
            ->expectsOutputToContain('The --tenant=<slug> option is required.')
            ->assertExitCode(1);
    }

    #[Test]
    public function scrape_company_fails_with_unknown_company_id(): void
    {
        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:company', ['company' => 999, '--tenant' => 'primary'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    #[Test]
    public function scrape_all_iterates_companies_and_aggregates_totals(): void
    {
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 5, newCount: 2, closedCount: 0));
        $this->fakeScraper(AtsType::LEVER, ScraperRunResult::notModified('W/"abc"'));
        $this->fakeScraper(AtsType::ASHBY, new ScraperRunResult(fetched: 0, newCount: 0, closedCount: 0, errors: [['message' => 'parse glitch']]));

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);
        Company::factory()->create(['ats_type' => 'ashby', 'ats_slug' => 'as-a']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary'])->assertExitCode(0);

        $this->assertSame(3, ScrapeRun::query()->count());
        $this->assertSame(1, ScrapeRun::query()->where('status', 'completed')->count());
        $this->assertSame(1, ScrapeRun::query()->where('status', 'not_modified')->count());
        $this->assertSame(1, ScrapeRun::query()->where('status', 'partial')->count());
    }

    #[Test]
    public function scrape_all_skips_companies_without_ats_type(): void
    {
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 1, newCount: 0, closedCount: 0));

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => null, 'ats_slug' => null]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary'])->assertExitCode(0);

        $this->assertSame(1, ScrapeRun::query()->count(), 'Companies without ats_type are not scraped.');
    }

    #[Test]
    public function scrape_all_source_filter_limits_to_one_ats(): void
    {
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 1, newCount: 0, closedCount: 0));
        $this->fakeScraper(AtsType::LEVER, new ScraperRunResult(fetched: 1, newCount: 0, closedCount: 0));

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--source' => 'greenhouse'])->assertExitCode(0);

        $this->assertSame(1, ScrapeRun::query()->count());
        $this->assertSame('greenhouse', ScrapeRun::query()->sole()->source);
    }

    #[Test]
    public function scrape_all_isolates_errors_from_one_company_and_continues(): void
    {
        $this->registerThrowingScraper(AtsType::GREENHOUSE);
        $this->fakeScraper(AtsType::LEVER, new ScraperRunResult(fetched: 1, newCount: 1, closedCount: 0));

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-bad']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-good']);

        app(TenantContext::class)->setCurrent(null);

        // Exit code 1 because at least one run errored, but both ran.
        $this->artisan('scrape:all', ['--tenant' => 'primary'])->assertExitCode(1);

        $this->assertSame(2, ScrapeRun::query()->count(), 'A failing company does not abort the rest.');
        $this->assertSame(1, ScrapeRun::query()->where('status', 'error')->count());
        $this->assertSame(1, ScrapeRun::query()->where('status', 'completed')->count());
    }

    #[Test]
    public function scrape_all_rejects_unknown_source_filter(): void
    {
        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--source' => 'bamboohr'])
            ->expectsOutputToContain('Unknown --source')
            ->assertExitCode(1);
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

            public function concurrency(): int
            {
                return 1;
            }

            public function run(Company $company): ScraperRunResult
            {
                return $this->result;
            }
        });
    }

    private function registerThrowingScraper(AtsType $type): void
    {
        /** @var ScraperRegistry $registry */
        $registry = $this->app->make(ScraperRegistry::class);
        $registry->register(new class($type) implements AtsScraperInterface
        {
            public function __construct(private AtsType $type) {}

            public function name(): AtsType
            {
                return $this->type;
            }

            public function concurrency(): int
            {
                return 1;
            }

            public function run(Company $company): ScraperRunResult
            {
                throw new RuntimeException('boom');
            }
        });
    }
}
