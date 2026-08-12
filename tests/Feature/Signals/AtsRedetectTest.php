<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\AtsType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScrapeOrchestrator;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\ScraperRunResult;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtsRedetectTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ScraperRegistry $registry;

    private ScrapeOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->registry = $this->app->make(ScraperRegistry::class);
        $this->orchestrator = new ScrapeOrchestrator($this->registry);
    }

    #[Test]
    public function seven_consecutive_404s_flip_ats_status_to_needs_redetection(): void
    {
        $this->registry->register($this->fourOhFourScraper(AtsType::GREENHOUSE));

        $company = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'acme',
            'ats_status' => 'ok',
            'ats_consecutive_404s' => 0,
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $this->orchestrator->runForCompany($company->fresh());
        }

        $this->assertSame('ok', $company->fresh()->ats_status);
        $this->assertSame(6, (int) $company->fresh()->ats_consecutive_404s);

        // Seventh strike trips the flag.
        $this->orchestrator->runForCompany($company->fresh());

        $reloaded = $company->fresh();
        $this->assertSame('needs_redetection', $reloaded->ats_status);
        $this->assertSame(7, (int) $reloaded->ats_consecutive_404s);
    }

    #[Test]
    public function a_successful_scrape_resets_the_404_counter(): void
    {
        $company = Company::factory()->create([
            'ats_type' => 'lever',
            'ats_slug' => 'acme',
            'ats_status' => 'ok',
            'ats_consecutive_404s' => 5,
        ]);

        $this->registry->register($this->fakeScraper(
            AtsType::LEVER,
            new ScraperRunResult(fetched: 3, newCount: 1, closedCount: 0),
        ));

        $this->orchestrator->runForCompany($company);

        $this->assertSame(0, (int) $company->fresh()->ats_consecutive_404s);
        $this->assertSame('ok', $company->fresh()->ats_status);
    }

    #[Test]
    public function redetect_command_picks_up_flagged_companies_and_records_event(): void
    {
        $flagged = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'oldslug',
            'careers_url' => 'https://acme.example.com/careers',
            'ats_status' => 'needs_redetection',
            'ats_consecutive_404s' => 7,
            'ats_redetect_attempts' => 0,
        ]);

        // Unrelated healthy company must be skipped.
        $healthy = Company::factory()->create([
            'ats_type' => 'lever',
            'ats_slug' => 'acme',
            'ats_status' => 'ok',
        ]);

        Http::fake([
            'acme.example.com/careers' => Http::response(
                '<html><body><iframe src="https://boards.greenhouse.io/acme-new"></iframe></body></html>',
                200,
            ),
        ]);

        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);

        $reloaded = $flagged->fresh();
        $this->assertSame('ok', $reloaded->ats_status);
        $this->assertSame('greenhouse', $reloaded->ats_type);
        $this->assertSame('acme-new', $reloaded->ats_slug);
        $this->assertSame(0, (int) $reloaded->ats_consecutive_404s);
        $this->assertSame(0, (int) $reloaded->ats_redetect_attempts);

        $event = Event::query()
            ->where('company_id', $flagged->id)
            ->where('type', 'ats_redetected')
            ->first();
        $this->assertNotNull($event);

        // Healthy company unchanged, no event written.
        $this->assertSame('ok', $healthy->fresh()->ats_status);
        $this->assertSame(0, Event::query()->where('company_id', $healthy->id)->count());
    }

    #[Test]
    public function three_redetect_failures_emit_ats_redetect_failed_event(): void
    {
        $company = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'oldslug',
            'careers_url' => 'https://nowhere.example.com/careers',
            'ats_status' => 'needs_redetection',
            'ats_consecutive_404s' => 9,
            'ats_redetect_attempts' => 0,
        ]);

        // Body contains no ATS fingerprint — detection always misses.
        Http::fake([
            'nowhere.example.com/careers' => Http::response('<html><body>We have no jobs.</body></html>', 200),
        ]);

        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);
        $this->assertSame(1, (int) $company->fresh()->ats_redetect_attempts);
        $this->assertSame('needs_redetection', $company->fresh()->ats_status);

        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);
        $this->assertSame(2, (int) $company->fresh()->ats_redetect_attempts);
        $this->assertSame('needs_redetection', $company->fresh()->ats_status);

        // Third strike flips to redetect_failed AND emits the event.
        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);

        $reloaded = $company->fresh();
        $this->assertSame(3, (int) $reloaded->ats_redetect_attempts);
        $this->assertSame('redetect_failed', $reloaded->ats_status);

        $event = Event::query()
            ->where('company_id', $company->id)
            ->where('type', 'ats_redetect_failed')
            ->first();
        $this->assertNotNull($event);

        // After locking, the company is no longer selected by the sweep
        // (status moved out of 'needs_redetection'), so a 4th invocation
        // is a no-op.
        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);
        $this->assertSame(3, (int) $company->fresh()->ats_redetect_attempts);
    }

    #[Test]
    public function redetecting_the_identical_ats_pair_counts_as_a_failed_attempt(): void
    {
        // The redetector re-checking the company's own (unchanged)
        // careers_url can "detect" the exact pair that just racked up
        // seven 404s. That must count as a failure, not a fix — otherwise
        // the failure counters reset forever and the company never
        // reaches redetect_failed for a human to notice.
        $company = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'acme-new',
            'careers_url' => 'https://acme.example.com/careers',
            'ats_status' => 'needs_redetection',
            'ats_consecutive_404s' => 7,
            'ats_redetect_attempts' => 0,
        ]);

        Http::fake([
            'acme.example.com/careers' => Http::response(
                '<html><body><iframe src="https://boards.greenhouse.io/acme-new"></iframe></body></html>',
                200,
            ),
        ]);

        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);

        $reloaded = $company->fresh();
        $this->assertSame('needs_redetection', $reloaded->ats_status);
        $this->assertSame(1, (int) $reloaded->ats_redetect_attempts);
        $this->assertSame(
            0,
            Event::query()->where('company_id', $company->id)->where('type', 'ats_redetected')->count(),
        );
    }

    #[Test]
    public function successful_switch_closes_open_roles_from_the_previous_source(): void
    {
        $flagged = Company::factory()->create([
            'ats_type' => 'greenhouse',
            'ats_slug' => 'oldslug',
            'careers_url' => 'https://acme.example.com/careers',
            'ats_status' => 'needs_redetection',
            'ats_consecutive_404s' => 7,
            'ats_redetect_attempts' => 0,
            'ats_host' => 'https://oldslug.jobs.personio.de',
        ]);

        $staleRole = Role::factory()->create([
            'company_id' => $flagged->id,
            'source' => 'greenhouse',
            'is_open' => true,
        ]);
        $alreadyClosedRole = Role::factory()->create([
            'company_id' => $flagged->id,
            'source' => 'greenhouse',
            'is_open' => false,
        ]);

        Http::fake([
            'acme.example.com/careers' => Http::response(
                '<html><body><iframe src="https://boards.greenhouse.io/acme-new"></iframe></body></html>',
                200,
            ),
        ]);

        Artisan::call('signals:ats:redetect', ['--tenant' => 'primary']);

        $reloaded = $flagged->fresh();
        $this->assertSame('acme-new', $reloaded->ats_slug);
        $this->assertNull($reloaded->ats_host);

        $this->assertFalse($staleRole->fresh()->is_open);
        $this->assertFalse($alreadyClosedRole->fresh()->is_open);

        $this->assertSame(
            1,
            Event::query()
                ->where('company_id', $flagged->id)
                ->where('role_id', $staleRole->id)
                ->where('type', 'role_closed')
                ->count(),
        );
        // The role that was already closed before the switch must not get
        // a duplicate close event.
        $this->assertSame(
            0,
            Event::query()
                ->where('company_id', $flagged->id)
                ->where('role_id', $alreadyClosedRole->id)
                ->where('type', 'role_closed')
                ->count(),
        );
    }

    private function fourOhFourScraper(AtsType $type): AtsScraperInterface
    {
        return $this->fakeScraper(
            $type,
            new ScraperRunResult(
                fetched: 0,
                newCount: 0,
                closedCount: 0,
                errors: [[
                    'message' => 'HTTP 404',
                    'context' => ['http_status' => 404],
                ]],
            ),
        );
    }

    private function fakeScraper(AtsType $type, ScraperRunResult $result): AtsScraperInterface
    {
        return new class($type, $result) implements AtsScraperInterface
        {
            public function __construct(
                private AtsType $type,
                private ScraperRunResult $result,
            ) {}

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
        };
    }
}
