<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AtsType;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\ScraperRunResult;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--source' => 'not-a-real-ats'])
            ->expectsOutputToContain('Unknown --source')
            ->assertExitCode(1);
    }

    #[Test]
    public function scrape_all_queue_dispatches_one_job_per_company_instead_of_running_inline(): void
    {
        Queue::fake();

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);

        Queue::assertPushed(RunCompanyScrapeJob::class, 2);
        $this->assertSame(0, ScrapeRun::query()->count(), 'Queued mode dispatches jobs; it does not run the orchestrator inline.');
    }

    #[Test]
    public function scrape_all_skips_graveyarded_and_redetect_failed_companies(): void
    {
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 1, newCount: 0, closedCount: 0));

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-good']);
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-graveyard', 'is_active' => false]);
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-gaveup', 'ats_status' => 'redetect_failed']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary'])->assertExitCode(0);

        $this->assertSame(1, ScrapeRun::query()->count());
    }

    #[Test]
    public function scrape_all_is_scheduled_hourly(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => $event->description === 'scrape:all',
        );

        $this->assertCount(1, $matching, 'scrape:all should be scheduled exactly once.');
        $this->assertSame('0 * * * *', $matching->first()->expression);
    }

    #[Test]
    public function scrape_all_queue_skips_a_tier_company_scraped_within_the_last_two_hours(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a', 'tier' => 'A']);
        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'completed',
            'started_at' => now()->subHour(),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);

        Queue::assertNotPushed(RunCompanyScrapeJob::class);
    }

    #[Test]
    public function scrape_all_queue_dispatches_an_a_tier_company_scraped_more_than_two_hours_ago(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a', 'tier' => 'A']);
        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'completed',
            'started_at' => now()->subHours(3),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }

    #[Test]
    public function scrape_all_queue_keeps_a_c_tier_company_that_ran_ten_hours_ago_not_due(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-c', 'tier' => 'C']);
        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'completed',
            'started_at' => now()->subHours(10),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);

        Queue::assertNotPushed(RunCompanyScrapeJob::class);
    }

    #[Test]
    public function scrape_all_queue_dispatches_a_c_tier_company_that_ran_more_than_a_day_ago(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-c', 'tier' => 'C']);
        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'completed',
            'started_at' => now()->subHours(25),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }

    #[Test]
    public function scrape_all_queue_always_dispatches_a_company_with_no_prior_runs(): void
    {
        Queue::fake();

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-new', 'tier' => 'C']);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }

    #[Test]
    public function scrape_all_queue_with_all_bypasses_the_due_filter(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-c', 'tier' => 'C']);
        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'completed',
            'started_at' => now()->subHour(),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true, '--all' => true])->assertExitCode(0);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }

    #[Test]
    public function scheduled_scrape_all_targets_the_tenant_that_owns_the_companies_not_the_lowest_id(): void
    {
        Queue::fake();

        // setUp() created an empty 'primary' tenant first (lowest id); the
        // companies live in a second tenant, as in the local dev database.
        $owner = Tenant::factory()->create(['slug' => 'default']);
        $company = Company::factory()->create([
            'tenant_id' => $owner->id,
            'ats_type' => 'greenhouse',
            'ats_slug' => 'gh-owned',
            'tier' => 'A',
        ]);

        // Leave the stale 'primary' context in place: the resolver must not
        // depend on whatever TenantContext an earlier closure left behind.
        $this->runScheduledEvent('scrape:all');

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
        Queue::assertPushed(
            RunCompanyScrapeJob::class,
            static fn (RunCompanyScrapeJob $job): bool => $job->tenantId === $owner->id && $job->companyId === $company->id,
        );
    }

    #[Test]
    public function scheduled_scrape_all_runs_once_per_tenant_that_owns_companies(): void
    {
        Queue::fake();

        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-primary', 'tier' => 'A']);
        $second = Tenant::factory()->create(['slug' => 'second']);
        Company::factory()->create([
            'tenant_id' => $second->id,
            'ats_type' => 'lever',
            'ats_slug' => 'lv-second',
            'tier' => 'A',
        ]);

        $this->runScheduledEvent('scrape:all');

        Queue::assertPushed(RunCompanyScrapeJob::class, 2);
        Queue::assertPushed(RunCompanyScrapeJob::class, fn (RunCompanyScrapeJob $job): bool => $job->tenantId === $this->tenant->id);
        Queue::assertPushed(RunCompanyScrapeJob::class, static fn (RunCompanyScrapeJob $job): bool => $job->tenantId === $second->id);
    }

    #[Test]
    public function scrape_all_skips_companies_awaiting_redetection(): void
    {
        Queue::fake();

        Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'gh-flagged',
            'tier' => 'A',
            'ats_status' => 'needs_redetection',
            'ats_consecutive_404s' => 7,
        ]);
        Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'gh-dead',
            'tier' => 'A',
            'ats_status' => 'redetect_failed',
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true, '--all' => true])->assertExitCode(0);

        Queue::assertNotPushed(RunCompanyScrapeJob::class);
    }

    #[Test]
    public function scrape_all_queue_backs_off_a_company_with_consecutive_failures(): void
    {
        Queue::fake();

        // A-tier (2h) with two consecutive failures: 2h * 2^2 = 8h interval.
        $company = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'gh-flaky',
            'tier' => 'A',
            'ats_consecutive_failures' => 2,
        ]);
        $run = ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'error',
            'started_at' => now()->subHours(5),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);
        Queue::assertNotPushed(RunCompanyScrapeJob::class);

        $run->forceFill(['started_at' => now()->subHours(9)])->save();

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);
        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }

    #[Test]
    public function scrape_all_queue_caps_the_backoff_at_seven_days(): void
    {
        Queue::fake();

        // C-tier (24h) with 8 failures would be 24h * 256 uncapped.
        $company = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'gh-down',
            'tier' => 'C',
            'ats_consecutive_failures' => 8,
        ]);
        $run = ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'status' => 'error',
            'started_at' => now()->subDays(6),
        ]);

        app(TenantContext::class)->setCurrent(null);

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);
        Queue::assertNotPushed(RunCompanyScrapeJob::class);

        $run->forceFill(['started_at' => now()->subDays(7)->subMinute()])->save();

        $this->artisan('scrape:all', ['--tenant' => 'primary', '--queue' => true])->assertExitCode(0);
        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }

    private function runScheduledEvent(string $description): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $event = collect($schedule->events())->first(
            static fn ($event): bool => $event->description === $description,
        );

        $this->assertNotNull($event, "{$description} should be scheduled.");

        $event->run($this->app);
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

            public function run(Company $company): ScraperRunResult
            {
                throw new RuntimeException('boom');
            }
        });
    }
}
