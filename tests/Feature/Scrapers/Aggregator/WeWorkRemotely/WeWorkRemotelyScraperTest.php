<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\WeWorkRemotely;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\WeWorkRemotely\Requests\CategoryFeedRequest;
use App\Scrapers\Aggregator\WeWorkRemotely\WeWorkRemotelyScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class WeWorkRemotelyScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private WeWorkRemotelyScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::WEWORKREMOTELY)->create();
        $this->scraper = $this->app->make(WeWorkRemotelyScraper::class);
    }

    private function fixture(string $name): string
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/weworkremotely/'.$name;

        return (string) file_get_contents($path);
    }

    /**
     * Faked sequence covering the three category feeds the scraper hits in
     * order: back-end, full-stack, programming. The back-end and full-stack
     * fixtures share Acme's guid so cross-feed dedup gets exercised.
     */
    private function fakeAllFeeds(): void
    {
        Saloon::fake([
            MockResponse::make($this->fixture('backend-jobs.rss'), 200),
            MockResponse::make($this->fixture('fullstack-jobs.rss'), 200),
            MockResponse::make($this->fixture('programming-jobs.rss'), 200),
        ]);
    }

    #[Test]
    public function happy_path_persists_each_item_with_company_split_from_title(): void
    {
        $this->fakeAllFeeds();

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        // 3 back-end + 1 dedup-skipped Acme + 1 Umbrella + 0 programming = 4 fetched
        $this->assertSame(4, $result->fetched);
        $this->assertSame(4, $result->newCount);
        $this->assertSame(4, AggregatorListing::query()->count());

        $acme = AggregatorListing::query()
            ->where('external_id', 'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();
        $this->assertSame('Acme Corp', $acme->parsed_company_name);
        $this->assertSame('Senior Backend Engineer', $acme->parsed_role_title);
        $this->assertSame('senior', $acme->parsed_seniority);
        $this->assertSame(
            'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001',
            $acme->url,
        );
        $this->assertSame(AggregatorListingStatus::NEW, $acme->status);
        // No salary in RSS — comp is MISSING.
        $this->assertFalse($acme->has_comp);
        $this->assertNull($acme->parsed_comp_min_usd);
    }

    #[Test]
    public function title_without_colon_falls_back_to_role_only_and_empty_company(): void
    {
        $this->fakeAllFeeds();

        $this->scraper->run($this->source);

        $initech = AggregatorListing::query()
            ->where('external_id', 'https://weworkremotely.com/remote-jobs/initech-fullstack-engineer-no-colon-003')
            ->firstOrFail();

        $this->assertNull($initech->parsed_company_name);
        $this->assertSame('Initech Fullstack Engineer No Colon Title', $initech->parsed_role_title);
    }

    #[Test]
    public function stack_tags_inferred_from_body_text(): void
    {
        $this->fakeAllFeeds();

        $this->scraper->run($this->source);

        $acme = AggregatorListing::query()
            ->where('external_id', 'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();

        $this->assertIsArray($acme->stack_tags);
        $this->assertContains('python', $acme->stack_tags);
        $this->assertContains('django', $acme->stack_tags);
        $this->assertContains('postgres', $acme->stack_tags);
    }

    #[Test]
    public function region_from_rss_field_drives_location_bucket(): void
    {
        $this->fakeAllFeeds();

        $this->scraper->run($this->source);

        $acme = AggregatorListing::query()
            ->where('external_id', 'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();

        // EMEA in <region> -> emea bucket via RegionExtractor.
        $this->assertSame('emea', $acme->location_bucket);
        // LocationExtractor stores the free-text region value in parsed.location.value.
        $this->assertSame('EMEA', $acme->parsed['location']['value'] ?? null);
        $this->assertSame('emea', $acme->parsed['region']['value'] ?? null);
    }

    #[Test]
    public function cross_feed_duplicate_guid_is_only_persisted_once(): void
    {
        $this->fakeAllFeeds();

        $result = $this->scraper->run($this->source);

        $this->assertSame(
            1,
            AggregatorListing::query()
                ->where('external_id', 'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001')
                ->count(),
        );
        $this->assertSame(4, $result->fetched);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        $this->fakeAllFeeds();
        $first = $this->scraper->run($this->source);
        $this->assertSame(4, $first->newCount);

        $this->fakeAllFeeds();
        $second = $this->scraper->run($this->source);

        $this->assertSame(4, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(4, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        $this->fakeAllFeeds();
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()
            ->where('external_id', 'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        $this->fakeAllFeeds();
        $this->scraper->run($this->source);

        $refreshed = AggregatorListing::query()
            ->where('external_id', 'https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();
        $this->assertSame(AggregatorListingStatus::KEPT, $refreshed->status);
    }

    #[Test]
    public function network_exception_per_feed_is_captured_in_errors(): void
    {
        Saloon::fake([
            CategoryFeedRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('weworkremotely 503');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        // One error per failed category feed (3 categories).
        $this->assertCount(3, $result->errors);
        $this->assertSame('weworkremotely 503', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
