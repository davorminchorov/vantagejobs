<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScrapeRun;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'health-check']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function up_endpoint_is_public(): void
    {
        // No auth, no redirect to /login regardless of healthy/unhealthy.
        $this->getJson('/up')->assertStatus(503);
    }

    #[Test]
    public function up_returns_200_when_database_reachable_and_a_recent_scrape_finished(): void
    {
        ScrapeRun::factory()->create([
            'finished_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->getJson('/up')
            ->assertOk()
            ->assertJson(['status' => 'up', 'reason' => null]);
    }

    #[Test]
    public function up_returns_503_when_no_scrape_finished_within_the_window(): void
    {
        // A scrape that finished 49h ago — older than the 48h default window.
        ScrapeRun::factory()->create([
            'finished_at' => CarbonImmutable::now()->subHours(49),
        ]);

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJson(['status' => 'down', 'reason' => 'no_recent_scrape']);
    }

    #[Test]
    public function up_returns_503_when_only_an_unfinished_scrape_exists(): void
    {
        ScrapeRun::factory()->create([
            'started_at' => CarbonImmutable::now()->subMinutes(5),
            'finished_at' => null,
        ]);

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJson(['status' => 'down', 'reason' => 'no_recent_scrape']);
    }

    #[Test]
    public function up_counts_recent_scrapes_across_tenants(): void
    {
        // A scrape owned by a different tenant should still satisfy liveness,
        // because /up is a fleet-wide signal evaluated without tenant scope.
        $other = Tenant::factory()->create(['slug' => 'other-tenant']);

        app(TenantContext::class)->withTenant($other, function (): void {
            ScrapeRun::factory()->create([
                'finished_at' => CarbonImmutable::now()->subHour(),
            ]);
        });

        // Reset context to none — liveness runs before tenant resolution.
        app(TenantContext::class)->setCurrent(null);

        $this->getJson('/up')
            ->assertOk()
            ->assertJson(['status' => 'up']);
    }

    #[Test]
    public function health_endpoint_requires_auth(): void
    {
        $this->get('/health')->assertRedirect('/login');
    }

    #[Test]
    public function health_endpoint_returns_rich_status_when_authenticated(): void
    {
        $user = User::factory()->create();
        ScrapeRun::factory()->create([
            'source' => 'greenhouse',
            'source_kind' => 'ats',
            'finished_at' => CarbonImmutable::now()->subHour(),
        ]);

        $response = $this->actingAs($user)->getJson('/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database' => ['ok', 'error'],
                    'scrapes' => ['ok', 'max_age_hours', 'sources'],
                    'queue' => ['ok', 'pending'],
                    'disk' => ['ok', 'free_bytes', 'total_bytes', 'free_percent'],
                ],
            ]);
    }

    #[Test]
    public function health_scrapes_check_reports_last_finished_per_source(): void
    {
        $user = User::factory()->create();

        ScrapeRun::factory()->create([
            'source' => 'lever',
            'source_kind' => 'ats',
            'finished_at' => CarbonImmutable::now()->subHour(),
        ]);
        ScrapeRun::factory()->create([
            'source' => 'hackernews',
            'source_kind' => 'aggregator',
            'finished_at' => CarbonImmutable::now()->subHours(72),
        ]);

        $response = $this->actingAs($user)->getJson('/health');

        $response->assertOk();
        $sources = $response->json('checks.scrapes.sources');
        $this->assertCount(2, $sources);

        $bySource = collect($sources)->keyBy('source');
        $this->assertFalse($bySource['lever']['stale']);
        $this->assertTrue($bySource['hackernews']['stale']);
    }
}
