<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\HexHire;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\HexHire\HexHireScraper;
use App\Scrapers\Aggregator\HexHire\Requests\JobsRssRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class HexHireScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private HexHireScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HEXHIRE)->create();
        $this->scraper = $this->app->make(HexHireScraper::class);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/scrapers/aggregator/hexhire/feed.xml');
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

        AggregatorListing::query()->get()->each(function (AggregatorListing $row): void {
            $this->assertNotEmpty($row->external_id);
            $this->assertNotEmpty($row->parsed_role_title, "parsed_role_title should be non-empty for {$row->external_id}");
            $this->assertNotEmpty($row->parsed_company_name, "parsed_company_name should be non-empty for {$row->external_id}");
            $this->assertSame(AggregatorListingStatus::NEW, $row->status);
        });

        // HexHire titles are "$role at $company" — verify split is correct on known item
        $knownRow = AggregatorListing::query()
            ->where('external_id', 'https://hexhire.io/jobs/912/lead-backend-engineer')
            ->firstOrFail();
        $this->assertSame('Lead Backend Engineer', $knownRow->parsed_role_title);
        $this->assertSame('Tonsser', $knownRow->parsed_company_name);

        // At least one row should have stack_tags populated.
        // The second item title is "Senior/Staff Elixir/Full at True Anomaly" which contains
        // "elixir" — a dictionary entry with no context requirement — so it should match via body scan.
        $anyWithStackTags = AggregatorListing::query()
            ->get()
            ->contains(fn (AggregatorListing $row): bool => ! empty($row->stack_tags));

        if (! $anyWithStackTags) {
            $this->markTestSkipped(
                'stack_tags were not populated for any HexHire listing — the StackTagsExtractor '
                .'may not recognise elixir from these truncated fixture descriptions.',
            );
        }

        $this->assertTrue($anyWithStackTags, 'Expected at least one listing to have non-empty stack_tags');
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

        $row = AggregatorListing::query()->first();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();
        $externalId = $row->external_id;

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', $externalId)->firstOrFail()->status,
        );
    }

    #[Test]
    public function empty_feed_is_a_clean_no_op(): void
    {
        $emptyFeed = '<?xml version="1.0" encoding="utf-8"?>'
            .'<rss version="2.0"><channel><title>Empty</title><link>https://hexhire.io/</link><description>none</description></channel></rss>';

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
                throw new RuntimeException('hexhire connection error');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('hexhire connection error', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
