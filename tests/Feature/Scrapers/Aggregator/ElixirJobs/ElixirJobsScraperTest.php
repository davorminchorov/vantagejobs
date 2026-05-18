<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\ElixirJobs;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\ElixirJobs\ElixirJobsScraper;
use App\Scrapers\Aggregator\ElixirJobs\Requests\JobsRssRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class ElixirJobsScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private ElixirJobsScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::ELIXIR_JOBS)->create();
        $this->scraper = $this->app->make(ElixirJobsScraper::class);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/scrapers/aggregator/elixir_jobs/feed.xml');
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        AggregatorListing::query()->get()->each(function (AggregatorListing $listing): void {
            $this->assertNotEmpty($listing->external_id);
            $this->assertNotEmpty($listing->parsed_role_title);
            $this->assertSame(AggregatorListingStatus::NEW, $listing->status);

            // Elixir Jobs titles are role-only; company name lives in the HTML
            // description and is not extracted by the current scraper.
            // parsed_company_name is therefore expected to be null — known gap.
            $this->assertNull($listing->parsed_company_name);
        });
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
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
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()
            ->where('external_id', 'https://elixirjobs.net/offers/hunit-senior-elixir-developer-e40f86a8')
            ->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $this->scraper->run($this->source);

        $refreshed = AggregatorListing::query()
            ->where('external_id', 'https://elixirjobs.net/offers/hunit-senior-elixir-developer-e40f86a8')
            ->firstOrFail();
        $this->assertSame(AggregatorListingStatus::KEPT, $refreshed->status);
    }

    #[Test]
    public function empty_feed_is_a_clean_no_op(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(
                '<?xml version="1.0" encoding="utf-8"?><rss version="2.0"><channel><title>Empty</title><link>https://elixirjobs.net/</link><description>none</description></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            JobsRssRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('elixirjobs network error');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('elixirjobs network error', $result->errors[0]['message']);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
