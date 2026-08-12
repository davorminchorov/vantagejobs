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
    private function fixture(string $name = 'job-board.json'): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/arbeitnow/'.$name;

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Page 1 (job-board.json, 3 jobs) has links.next set; page 2
     * (job-board-page-2.json, 1 job) has links.next null, so the scraper
     * walks exactly two pages and stops.
     */
    private function fakeTwoPages(): void
    {
        Saloon::fake([
            MockResponse::make($this->fixture('job-board.json'), 200),
            MockResponse::make($this->fixture('job-board-page-2.json'), 200),
        ]);
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        $this->fakeTwoPages();

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(4, $result->fetched);
        $this->assertSame(4, $result->newCount);
        $this->assertSame(4, AggregatorListing::query()->count());

        AggregatorListing::query()->get()->each(function (AggregatorListing $row): void {
            $this->assertNotEmpty($row->external_id);
            $this->assertNotEmpty($row->parsed_company_name);
            $this->assertNotEmpty($row->parsed_role_title);
            $this->assertSame(AggregatorListingStatus::NEW, $row->status);
        });
    }

    #[Test]
    public function second_page_job_is_ingested_after_the_first_page_is_exhausted(): void
    {
        $this->fakeTwoPages();

        $this->scraper->run($this->source);

        $row = AggregatorListing::query()
            ->where('external_id', 'senior-devops-engineer-berlin-405112')
            ->firstOrFail();

        $this->assertSame('Northwind GmbH', $row->parsed_company_name);
    }

    #[Test]
    public function stops_paginating_once_links_next_is_null(): void
    {
        $this->fakeTwoPages();

        $result = $this->scraper->run($this->source);

        // 3 (page 1) + 1 (page 2) jobs, and no third request is ever made —
        // Saloon::fake only queued two responses, so a third send() would
        // throw if the loop kept going past links.next === null.
        $this->assertFalse($result->hasErrors());
        $this->assertSame(4, $result->fetched);
    }

    #[Test]
    public function pagination_truncated_at_max_pages_is_reported_as_an_error(): void
    {
        config(['scrapers.aggregators.arbeitnow.max_pages' => 2]);

        // Both pages report a job-board.json-shaped payload that still has
        // links.next set, so page 2 (the cap) is reached with more upstream
        // data left unfetched.
        Saloon::fake([
            MockResponse::make($this->fixture('job-board.json'), 200),
            MockResponse::make($this->fixture('job-board.json'), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $lastError = $result->errors[array_key_last($result->errors)];
        $this->assertStringContainsString('truncated at max_pages (2)', $lastError['message']);
        $this->assertSame(2, $lastError['context']['max_pages']);
        // Same 3 slugs repeated on both pages — deduped within the run.
        $this->assertSame(3, $result->fetched);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        $this->fakeTwoPages();
        $first = $this->scraper->run($this->source);
        $this->assertSame(4, $first->newCount);

        $this->fakeTwoPages();
        $second = $this->scraper->run($this->source);

        $this->assertSame(4, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(4, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        $this->fakeTwoPages();
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->first();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();
        $externalId = $row->external_id;

        $this->fakeTwoPages();
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
            JobBoardRequest::class => MockResponse::make(['data' => [], 'links' => ['next' => null], 'meta' => []], 200),
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
