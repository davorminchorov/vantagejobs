<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\DjangoNews;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\DjangoNews\DjangoNewsScraper;
use App\Scrapers\Aggregator\DjangoNews\Requests\JobsRssRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class DjangoNewsScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private DjangoNewsScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::DJANGO_NEWS)->create();
        $this->scraper = $this->app->make(DjangoNewsScraper::class);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../Fixtures/scrapers/aggregator/django_news/feed.xml');
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
            $this->assertNotEmpty($row->parsed_role_title);
            $this->assertSame(AggregatorListingStatus::NEW, $row->status);

            // parsed_company_name is NOT asserted here.
            // Django Job Board titles are role-only; company is embedded only in the HTML
            // description body — no extractor currently recovers it from that field.
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
            .'<rss version="2.0"><channel><title>Empty</title><link>https://djangojobboard.com/</link><description>none</description></channel></rss>';

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
                throw new RuntimeException('django news connection error');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('django news connection error', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function http_error_status_is_recorded_without_persisting(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(body: 'Service Unavailable', status: 503),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(503, $result->errors[0]['context']['http_status']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function feed_missing_channel_element_is_a_structured_error_not_a_quiet_run(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(
                '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
