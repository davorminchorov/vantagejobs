<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\FourDayWeek\FourDayWeekScraper;
use App\Scrapers\Aggregator\FourDayWeek\Requests\JobsRssRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class FourDayWeekScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private FourDayWeekScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::FOURDAYWEEK)->create();
        $this->scraper = $this->app->make(FourDayWeekScraper::class);
    }

    private function fixture(): string
    {
        $path = __DIR__.'/../../../Fixtures/scrapers/aggregator/fourdayweek/jobs.rss';

        return (string) file_get_contents($path);
    }

    #[Test]
    public function happy_path_persists_each_item_from_feed(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(2, $result->fetched);
        $this->assertSame(2, $result->newCount);
        $this->assertSame(2, AggregatorListing::query()->count());

        $acme = AggregatorListing::query()
            ->where('external_id', 'https://4dayweek.io/jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();
        $this->assertSame(
            'https://4dayweek.io/jobs/acme-corp-senior-backend-engineer-001',
            $acme->url,
        );
        $this->assertSame(AggregatorListingStatus::NEW, $acme->status);
        // No salary in RSS — comp is MISSING.
        $this->assertFalse($acme->has_comp);
        $this->assertNull($acme->parsed_comp_min_usd);

        // "Company - Role" title shape.
        $this->assertSame('Acme Corp', $acme->parsed_company_name);
        $this->assertSame('Senior Backend Engineer', $acme->parsed_role_title);
        $this->assertSame('acme', $acme->normalized_company_key);

        // "Role at Company" title shape.
        $globex = AggregatorListing::query()
            ->where('external_id', 'https://4dayweek.io/jobs/globex-staff-platform-engineer-002')
            ->firstOrFail();
        $this->assertSame('Globex', $globex->parsed_company_name);
        $this->assertSame('Staff Platform Engineer', $globex->parsed_role_title);
        $this->assertSame('globex', $globex->normalized_company_key);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(2, $first->newCount);

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $second = $this->scraper->run($this->source);

        $this->assertSame(2, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(2, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()
            ->where('external_id', 'https://4dayweek.io/jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $refreshed = AggregatorListing::query()
            ->where('external_id', 'https://4dayweek.io/jobs/acme-corp-senior-backend-engineer-001')
            ->firstOrFail();
        $this->assertSame(AggregatorListingStatus::KEPT, $refreshed->status);
    }

    #[Test]
    public function empty_feed_is_a_clean_no_op(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(
                '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Empty</title></channel></rss>',
                200,
            ),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function malformed_xml_produces_structured_error(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make('<not xml', 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('malformed RSS XML', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            JobsRssRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('fourdayweek 503');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('fourdayweek 503', $result->errors[0]['message']);
        $this->assertSame(0, $result->fetched);
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
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function feed_missing_channel_element_is_a_structured_error_not_a_quiet_run(): void
    {
        // Well-formed XML, but the <channel> element the parser expects is
        // gone - schema drift, not "no jobs today". Must not look like a
        // clean completed run with fetched: 0.
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(
                '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"></rss>',
                200,
            ),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
