<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Remotive;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Remotive\Requests\RemoteJobsRequest;
use App\Scrapers\Aggregator\Remotive\RemotiveScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class RemotiveScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private RemotiveScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $this->scraper = $this->app->make(RemotiveScraper::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/remotive/category-software-dev.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        $acme = AggregatorListing::query()->where('external_id', '70001')->firstOrFail();
        $this->assertSame('Acme Corp', $acme->parsed_company_name);
        $this->assertSame('Senior Backend Engineer', $acme->parsed_role_title);
        $this->assertSame('senior', $acme->parsed_seniority);
        $this->assertSame('full_time', $acme->parsed['employment_type']['value'] ?? null);
        $this->assertSame(150000, $acme->parsed_comp_min_usd);
        $this->assertSame(200000, $acme->parsed_comp_max_usd);
        $this->assertTrue($acme->has_comp);
        $this->assertContains('python', $acme->stack_tags);
        $this->assertContains('django', $acme->stack_tags);
        $this->assertSame('acme', $acme->normalized_company_key);
        $this->assertSame(AggregatorListingStatus::NEW, $acme->status);
        $this->assertSame(
            'https://remotive.com/remote-jobs/software-dev/senior-backend-acme-70001',
            $acme->url,
        );
    }

    #[Test]
    public function eur_compensation_normalizes_to_usd(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $this->scraper->run($this->source);

        $fluxhq = AggregatorListing::query()->where('external_id', '70003')->firstOrFail();
        $comp = $fluxhq->parsed['compensation']['value'];

        $this->assertSame('EUR', $comp['currency']);
        $this->assertSame(100000, $comp['min']);
        $this->assertSame(140000, $comp['max']);
        $this->assertSame(108000, $fluxhq->parsed_comp_min_usd);
        $this->assertSame(151200, $fluxhq->parsed_comp_max_usd);
    }

    #[Test]
    public function ml_role_classifies_discipline_and_picks_up_us_region(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $this->scraper->run($this->source);

        $cyberdyne = AggregatorListing::query()->where('external_id', '70002')->firstOrFail();
        $this->assertSame('ml', $cyberdyne->parsed['discipline']['value']);
        $this->assertSame('us', $cyberdyne->location_bucket);
        $this->assertFalse($cyberdyne->parsed['visa_sponsorship']['value']);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $second = $this->scraper->run($this->source);

        $this->assertSame(3, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->where('external_id', '70001')->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', '70001')->firstOrFail()->status,
        );
    }

    #[Test]
    public function empty_jobs_array_is_a_clean_no_op(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make(['job-count' => 0, 'jobs' => []], 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('remotive 429');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('remotive 429', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
