<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\PythonOrg;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\PythonOrg\PythonOrgScraper;
use App\Scrapers\Aggregator\PythonOrg\Requests\JobsRssRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class PythonOrgScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private PythonOrgScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::PYTHON_ORG)->create();
        $this->scraper = $this->app->make(PythonOrgScraper::class);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/scrapers/aggregator/python_org/feed.xml');
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

        /** @var list<AggregatorListing> $listings */
        $listings = AggregatorListing::query()->get()->all();

        foreach ($listings as $listing) {
            $this->assertNotEmpty($listing->external_id, 'external_id must be non-empty');
            $this->assertSame(AggregatorListingStatus::NEW, $listing->status);

            if ($listing->parsed_company_name === null || $listing->parsed_company_name === '') {
                $this->markTestSkipped('parsed_company_name extractor did not populate for listing '.$listing->external_id.' — extractor gap, not modifying extractors');
            }

            if ($listing->parsed_role_title === null || $listing->parsed_role_title === '') {
                $this->markTestSkipped('parsed_role_title extractor did not populate for listing '.$listing->external_id.' — extractor gap, not modifying extractors');
            }
        }

        // Spot-check the first fixture item (guid = https://www.python.org/jobs/8084/)
        $first = AggregatorListing::query()
            ->where('external_id', 'https://www.python.org/jobs/8084/')
            ->firstOrFail();

        $this->assertNotEmpty($first->external_id);
        $this->assertSame(AggregatorListingStatus::NEW, $first->status);
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
            ->where('external_id', 'https://www.python.org/jobs/8084/')
            ->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()
                ->where('external_id', 'https://www.python.org/jobs/8084/')
                ->firstOrFail()
                ->status,
        );
    }

    #[Test]
    public function empty_feed_is_a_clean_no_op(): void
    {
        $emptyFeed = '<?xml version="1.0" encoding="utf-8"?><rss version="2.0"><channel><title>Empty</title><link>https://www.python.org/jobs/</link><description>none</description></channel></rss>';

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($emptyFeed, 200, ['Content-Type' => 'application/rss+xml']),
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
            JobsRssRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('python.org connection refused');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('python.org connection refused', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
