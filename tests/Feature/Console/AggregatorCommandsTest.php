<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindLatestThreadRequest;
use App\Scrapers\Aggregator\Remotive\Requests\RemoteJobsRequest;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class AggregatorCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
    }

    #[Test]
    public function aggregator_run_requires_tenant(): void
    {
        $exit = Artisan::call('aggregator:run', ['source' => 'hackernews']);
        $this->assertSame(1, $exit);
    }

    #[Test]
    public function aggregator_run_rejects_unknown_source(): void
    {
        $exit = Artisan::call('aggregator:run', ['source' => 'craigslist', '--tenant' => 'primary']);
        $this->assertSame(1, $exit);
    }

    #[Test]
    public function aggregator_run_fails_when_source_not_seeded(): void
    {
        // Tenant has no aggregator_sources row for HackerNews.
        $exit = Artisan::call('aggregator:run', ['source' => 'hackernews', '--tenant' => 'primary']);
        $this->assertSame(1, $exit);
    }

    #[Test]
    public function aggregator_run_executes_orchestrator(): void
    {
        AggregatorSource::factory()
            ->ofType(AggregatorSourceType::REMOTIVE)
            ->state(['tenant_id' => $this->tenant->id])
            ->create();

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make([
                'job-count' => 0,
                'jobs' => [],
            ], 200),
        ]);

        $exit = Artisan::call('aggregator:run', ['source' => 'remotive', '--tenant' => 'primary']);

        $this->assertSame(0, $exit);
        $run = ScrapeRun::query()->where('source', 'remotive')->sole();
        $this->assertSame('aggregator', $run->source_kind);
        $this->assertSame('completed', $run->status);
    }

    #[Test]
    public function aggregator_run_all_iterates_only_enabled_sources(): void
    {
        AggregatorSource::factory()
            ->ofType(AggregatorSourceType::REMOTIVE)
            ->state(['tenant_id' => $this->tenant->id, 'enabled' => true])
            ->create();
        AggregatorSource::factory()
            ->ofType(AggregatorSourceType::HIMALAYAS)
            ->state(['tenant_id' => $this->tenant->id, 'enabled' => false])
            ->create();

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make(['jobs' => []], 200),
        ]);

        $exit = Artisan::call('aggregator:run-all', ['--tenant' => 'primary']);
        $this->assertSame(0, $exit);

        $this->assertSame(1, ScrapeRun::query()->where('source_kind', 'aggregator')->count());
        $this->assertSame('remotive', ScrapeRun::query()->where('source_kind', 'aggregator')->sole()->source);
    }

    #[Test]
    public function aggregator_run_all_include_disabled_runs_everything(): void
    {
        AggregatorSource::factory()
            ->ofType(AggregatorSourceType::HACKERNEWS)
            ->state(['tenant_id' => $this->tenant->id, 'enabled' => false])
            ->create();

        Saloon::fake([
            FindLatestThreadRequest::class => MockResponse::make(['hits' => []], 200),
            FetchThreadRequest::class => MockResponse::make(['children' => []], 200),
        ]);

        Artisan::call('aggregator:run-all', ['--tenant' => 'primary', '--include-disabled' => true]);

        $this->assertSame(1, ScrapeRun::query()->where('source_kind', 'aggregator')->count());
    }

    #[Test]
    public function aggregator_run_persists_listings_through_full_pipeline(): void
    {
        AggregatorSource::factory()
            ->ofType(AggregatorSourceType::REMOTIVE)
            ->state(['tenant_id' => $this->tenant->id])
            ->create();

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make([
                'jobs' => [
                    [
                        'id' => 9001,
                        'url' => 'https://remotive.com/jobs/9001',
                        'title' => 'Senior Backend Engineer',
                        'company_name' => 'Acme Corp',
                        'tags' => ['python'],
                        'job_type' => 'full_time',
                        'publication_date' => '2026-05-10T10:00:00',
                        'candidate_required_location' => 'EMEA',
                        'salary' => '$150,000 - $200,000',
                        'description' => '<p>Hello.</p>',
                    ],
                ],
            ], 200),
        ]);

        Artisan::call('aggregator:run', ['source' => 'remotive', '--tenant' => 'primary']);

        $this->assertSame(1, AggregatorListing::query()->count());
    }
}
