<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Arbeitnow;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Arbeitnow\ArbeitnowScraper;
use App\Scrapers\Aggregator\Arbeitnow\Requests\JobBoardRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class ArbeitnowScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private ArbeitnowScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::ARBEITNOW)->create();
        $this->scraper = $this->app->make(ArbeitnowScraper::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/arbeitnow/job-board.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        AggregatorListing::query()->get()->each(function (AggregatorListing $row): void {
            $this->assertNotEmpty($row->external_id);
            $this->assertNotEmpty($row->parsed_company_name);
            $this->assertNotEmpty($row->parsed_role_title);
            $this->assertSame(AggregatorListingStatus::NEW, $row->status);
        });
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
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
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->first();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();
        $externalId = $row->external_id;

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', $externalId)->firstOrFail()->status,
        );
    }

    #[Test]
    public function empty_data_array_is_a_clean_no_op(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(['data' => [], 'links' => [], 'meta' => []], 200),
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
            JobBoardRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('arbeitnow 500');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('arbeitnow 500', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
