<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\WorkingNomads;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\WorkingNomads\Requests\ExposedJobsRequest;
use App\Scrapers\Aggregator\WorkingNomads\WorkingNomadsScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class WorkingNomadsScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private WorkingNomadsScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::WORKINGNOMADS)->create();
        $this->scraper = $this->app->make(WorkingNomadsScraper::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payload(): array
    {
        return [
            [
                'url' => 'https://www.workingnomads.co/jobs/acme-senior-backend-engineer',
                'title' => 'Senior Backend Engineer',
                'company_name' => 'Acme Corp',
                'category_name' => 'Development',
                'tags' => 'python,django,postgres',
                'location' => 'Worldwide',
                'description' => '<p>We are hiring a senior backend engineer.</p><p>Stack: Python, Django.</p>',
                'pub_date' => '2026-05-15T10:00:00',
            ],
            [
                'url' => 'https://www.workingnomads.co/jobs/blockchainery-solidity',
                'title' => 'Senior Solidity Engineer',
                'company_name' => 'Blockchainery',
                'category_name' => 'Development',
                'tags' => 'solidity,ethereum',
                'location' => 'Remote',
                'description' => '<p>Build smart contracts.</p>',
                'pub_date' => '2026-05-14T12:00:00',
            ],
            [
                'url' => 'https://www.workingnomads.co/jobs/flux-go-kubernetes',
                'title' => 'Platform Engineer',
                'company_name' => 'Flux',
                'category_name' => 'DevOps',
                'tags' => 'go,kubernetes',
                'location' => 'EMEA',
                'description' => '<p>Run Kubernetes at scale.</p>',
                'pub_date' => '2026-05-13T08:30:00',
            ],
        ];
    }

    #[Test]
    public function happy_path_persists_each_job_with_sha1_external_id(): void
    {
        Saloon::fake([
            ExposedJobsRequest::class => MockResponse::make($this->payload(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        $expectedId = sha1('https://www.workingnomads.co/jobs/acme-senior-backend-engineer');
        $acme = AggregatorListing::query()->where('external_id', $expectedId)->firstOrFail();

        $this->assertSame(
            'https://www.workingnomads.co/jobs/acme-senior-backend-engineer',
            $acme->url,
        );
        $this->assertSame(AggregatorListingStatus::NEW, $acme->status);
        $this->assertSame($this->source->id, $acme->source_id);
        $this->assertNotNull($acme->posted_at);
    }

    #[Test]
    public function jobs_missing_a_url_are_skipped(): void
    {
        $payload = $this->payload();
        $payload[] = [
            'title' => 'Ghost Role',
            'company_name' => 'No URL Co',
            'description' => '<p>Hidden</p>',
            'pub_date' => '2026-05-12T00:00:00',
        ];

        Saloon::fake([
            ExposedJobsRequest::class => MockResponse::make($payload, 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            ExposedJobsRequest::class => MockResponse::make($this->payload(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            ExposedJobsRequest::class => MockResponse::make($this->payload(), 200),
        ]);
        $second = $this->scraper->run($this->source);

        $this->assertSame(3, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            ExposedJobsRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('workingnomads 503');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('workingnomads 503', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
