<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Himalayas;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Himalayas\HimalayasScraper;
use App\Scrapers\Aggregator\Himalayas\Requests\JobsSearchRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class HimalayasScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private HimalayasScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create();
        $this->scraper = $this->app->make(HimalayasScraper::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/himalayas/jobs-search.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        $acme = AggregatorListing::query()->where('external_id', 'acme-staff-fullstack-engineer')->firstOrFail();
        $this->assertSame('Acme', $acme->parsed_company_name);
        $this->assertSame('Staff Fullstack Engineer', $acme->parsed_role_title);
        $this->assertSame('staff', $acme->parsed_seniority);
        $this->assertSame('full_time', $acme->parsed['employment_type']['value'] ?? null);
        $this->assertSame(180000, $acme->parsed_comp_min_usd);
        $this->assertSame(260000, $acme->parsed_comp_max_usd);
        $this->assertTrue($acme->has_comp);
        $this->assertSame('acme', $acme->normalized_company_key);
        $this->assertSame(AggregatorListingStatus::NEW, $acme->status);
        $this->assertSame('https://acme.com/jobs/staff-fs', $acme->url);
    }

    #[Test]
    public function eur_compensation_normalizes_to_usd(): void
    {
        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $this->scraper->run($this->source);

        $northwind = AggregatorListing::query()->where('external_id', 'northwind-senior-platform-engineer')->firstOrFail();
        $comp = $northwind->parsed['compensation']['value'];

        $this->assertSame('EUR', $comp['currency']);
        $this->assertSame(80000, $comp['min']);
        $this->assertSame(110000, $comp['max']);
        $this->assertSame(86400, $northwind->parsed_comp_min_usd);
        $this->assertSame(118800, $northwind->parsed_comp_max_usd);
    }

    #[Test]
    public function timezone_restrictions_are_preserved_as_explicit_remote_signal(): void
    {
        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $this->scraper->run($this->source);

        $cet = AggregatorListing::query()->where('external_id', 'cetcorp-senior-backend-engineer')->firstOrFail();
        $remote = $cet->parsed['remote'];

        $this->assertSame(['UTC+01:00', 'UTC+02:00'], $remote['value']['timezone_restrictions']);
        $this->assertSame('explicit', $remote['source']);
        $this->assertSame(0.9, $remote['confidence']);
        $this->assertNotNull($cet->parsed_remote_type);
        $this->assertContains('EMEA', $remote['value']['geo_restrictions']);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
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
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->where('external_id', 'acme-staff-fullstack-engineer')->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', 'acme-staff-fullstack-engineer')->firstOrFail()->status,
        );
    }

    #[Test]
    public function empty_jobs_array_is_a_clean_no_op(): void
    {
        Saloon::fake([
            JobsSearchRequest::class => MockResponse::make(['jobs' => [], 'total' => 0], 200),
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
            JobsSearchRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('himalayas 429');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('himalayas 429', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
