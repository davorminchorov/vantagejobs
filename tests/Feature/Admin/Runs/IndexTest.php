<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/admin/runs')->assertRedirect('/login');
    }

    #[Test]
    public function lists_runs_newest_first_and_paginates(): void
    {
        $company = Company::factory()->create();

        // 30 ATS runs to exercise pagination (per_page = 25).
        for ($i = 0; $i < 30; $i++) {
            ScrapeRun::factory()->create([
                'company_id' => $company->id,
                'source_kind' => 'ats',
                'source' => 'greenhouse',
                'started_at' => CarbonImmutable::now()->subMinutes($i),
                'finished_at' => CarbonImmutable::now()->subMinutes($i)->addSeconds(5),
            ]);
        }

        $response = $this->actingAs($this->user)->get('/admin/runs');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(30, $props['runs']['meta']['total']);
        $this->assertSame(25, count($props['runs']['data']));
        $this->assertSame(1, $props['runs']['meta']['current_page']);
        $this->assertSame(2, $props['runs']['meta']['last_page']);

        // Newest first: the first row should have the largest started_at.
        $first = $props['runs']['data'][0];
        $last = $props['runs']['data'][count($props['runs']['data']) - 1];
        $this->assertGreaterThanOrEqual($last['started_at'], $first['started_at']);
    }

    #[Test]
    public function source_kind_filter_round_trips(): void
    {
        $company = Company::factory()->create();
        $aggSource = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source_kind' => 'ats',
            'source' => 'greenhouse',
        ]);
        ScrapeRun::factory()->create([
            'company_id' => null,
            'source_kind' => 'aggregator',
            'source' => $aggSource->type->value,
        ]);

        $response = $this->actingAs($this->user)->get('/admin/runs?source_kind=aggregator');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['runs']['meta']['total']);
        $this->assertSame('aggregator', $props['runs']['data'][0]['source_kind']);
        $this->assertSame('aggregator', $props['filters']['source_kind']);
    }

    #[Test]
    public function status_filter_round_trips(): void
    {
        $company = Company::factory()->create();

        ScrapeRun::factory()->create(['company_id' => $company->id, 'status' => 'completed']);
        ScrapeRun::factory()->create(['company_id' => $company->id, 'status' => 'error']);
        ScrapeRun::factory()->create(['company_id' => $company->id, 'status' => 'partial']);

        $response = $this->actingAs($this->user)->get('/admin/runs?status=error');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['runs']['meta']['total']);
        $this->assertSame('error', $props['runs']['data'][0]['status']);
        $this->assertSame('error', $props['filters']['status']);
    }

    #[Test]
    public function date_range_filter_round_trips(): void
    {
        $company = Company::factory()->create();
        $now = CarbonImmutable::now();

        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'started_at' => $now->subDays(20),
            'finished_at' => $now->subDays(20)->addSeconds(5),
        ]);
        ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'started_at' => $now->subDays(2),
            'finished_at' => $now->subDays(2)->addSeconds(5),
        ]);

        $from = $now->subDays(7)->toDateString();
        $to = $now->toDateString();
        $response = $this->actingAs($this->user)->get("/admin/runs?date_from={$from}&date_to={$to}");

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['runs']['meta']['total']);
        $this->assertSame($from, $props['filters']['date_from']);
        $this->assertSame($to, $props['filters']['date_to']);
    }

    #[Test]
    public function unknown_query_keys_are_silently_ignored(): void
    {
        Company::factory()->create();
        ScrapeRun::factory()->create();

        $response = $this->actingAs($this->user)->get('/admin/runs?garbage=yes&source_kind=banana&status=fake');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['filters']['source_kind']);
        $this->assertNull($props['filters']['status']);
    }

    #[Test]
    public function source_health_strip_surfaces_failing_aggregator_sources(): void
    {
        AggregatorSource::factory()
            ->ofType(AggregatorSourceType::HACKERNEWS)
            ->create([
                'consecutive_failures' => 3,
                'last_error' => 'no thread found',
                'last_run_at' => CarbonImmutable::now()->subHour(),
            ]);

        $response = $this->actingAs($this->user)->get('/admin/runs');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['health']);
        $this->assertSame('aggregator', $props['health'][0]['kind']);
        $this->assertStringContainsString('no thread found', $props['health'][0]['reason']);
    }

    #[Test]
    public function it_exposes_trigger_options_and_active_run_count(): void
    {
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-b']);
        Company::factory()->create(['ats_type' => null, 'ats_slug' => null]);
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        // Aggregator-kind running rows (company_id null) so the factory does
        // not spawn stray ATS companies that would skew the ATS type counts.
        ScrapeRun::factory()->create([
            'company_id' => null,
            'source_kind' => 'aggregator',
            'source' => 'remotive',
            'status' => 'running',
            'started_at' => CarbonImmutable::now()->subMinutes(2),
            'finished_at' => null,
        ]);
        // Stale running row (older than 1h) must not be counted.
        ScrapeRun::factory()->create([
            'company_id' => null,
            'source_kind' => 'aggregator',
            'source' => 'remotive',
            'status' => 'running',
            'started_at' => CarbonImmutable::now()->subHours(3),
            'finished_at' => null,
        ]);

        $response = $this->actingAs($this->user)->get('/admin/runs');
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(1, $props['activeRunCount']);

        $atsTypes = collect($props['triggerOptions']['ats_types']);
        $greenhouse = $atsTypes->firstWhere('value', 'greenhouse');
        $this->assertNotNull($greenhouse);
        $this->assertSame(2, $greenhouse['count']);
        // A type with zero companies (e.g. lever) is omitted.
        $this->assertNull($atsTypes->firstWhere('value', 'lever'));

        $aggregators = collect($props['triggerOptions']['aggregators']);
        $this->assertNotNull($aggregators->firstWhere('value', 'remotive'));
    }
}
