<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Adzuna;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Adzuna\AdzunaScraper;
use App\Scrapers\Aggregator\Adzuna\Requests\SearchRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class AdzunaScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private AdzunaScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'scrapers.aggregators.adzuna.app_id' => 'test',
            'scrapers.aggregators.adzuna.app_key' => 'k',
            'scrapers.aggregators.adzuna.countries' => 'gb',
            'scrapers.aggregators.adzuna.pages_per_country' => 1,
        ]);

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::ADZUNA)->create();
        $this->scraper = $this->app->make(AdzunaScraper::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'count' => 3,
            'results' => [
                [
                    'id' => '1001',
                    'title' => 'Senior Backend Engineer',
                    'description' => '<p>Build distributed systems in Go and Rust.</p>',
                    'company' => ['display_name' => 'Acme Corp'],
                    'location' => [
                        'area' => ['UK', 'London'],
                        'display_name' => 'London, UK',
                    ],
                    'category' => ['label' => 'IT Jobs'],
                    'created' => '2026-05-15T10:00:00Z',
                    'redirect_url' => 'https://adzuna.example/1001',
                    'salary_min' => 80000,
                    'salary_max' => 110000,
                    'salary_is_predicted' => '0',
                    'contract_type' => 'permanent',
                    'contract_time' => 'full_time',
                ],
                [
                    'id' => '1002',
                    'title' => 'Staff Platform Engineer',
                    'description' => '<p>Kubernetes, Terraform, observability.</p>',
                    'company' => ['display_name' => 'Northwind'],
                    'location' => [
                        'area' => ['UK', 'Manchester'],
                        'display_name' => 'Manchester, UK',
                    ],
                    'category' => ['label' => 'IT Jobs'],
                    'created' => '2026-05-14T08:00:00Z',
                    'redirect_url' => 'https://adzuna.example/1002',
                    'salary_min' => 95000,
                    'salary_max' => 130000,
                    'salary_is_predicted' => '0',
                    'contract_type' => 'permanent',
                    'contract_time' => 'full_time',
                ],
                [
                    'id' => '1003',
                    'title' => 'Software Engineer',
                    'description' => '<p>Python, Django, PostgreSQL.</p>',
                    'company' => ['display_name' => 'Initech'],
                    'location' => [
                        'area' => ['UK', 'Bristol'],
                        'display_name' => 'Bristol, UK',
                    ],
                    'category' => ['label' => 'IT Jobs'],
                    'created' => '2026-05-13T12:00:00Z',
                    'redirect_url' => 'https://adzuna.example/1003',
                    'salary_min' => 60000,
                    'salary_max' => 80000,
                    'salary_is_predicted' => '0',
                    'contract_type' => 'permanent',
                    'contract_time' => 'full_time',
                ],
            ],
        ];
    }

    #[Test]
    public function happy_path_persists_each_result_with_country_in_raw(): void
    {
        Saloon::fake([
            SearchRequest::class => MockResponse::make($this->payload(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        $row = AggregatorListing::query()->where('external_id', '1001')->firstOrFail();
        $this->assertSame('gb', $row->raw['country'] ?? null);
        $this->assertSame('https://adzuna.example/1001', $row->url);
        $this->assertSame(AggregatorListingStatus::NEW, $row->status);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            SearchRequest::class => MockResponse::make($this->payload(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            SearchRequest::class => MockResponse::make($this->payload(), 200),
        ]);
        $second = $this->scraper->run($this->source);

        $this->assertSame(3, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());
    }

    #[Test]
    public function empty_results_array_is_a_clean_no_op(): void
    {
        Saloon::fake([
            SearchRequest::class => MockResponse::make(['count' => 0, 'results' => []], 200),
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
            SearchRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('adzuna 429');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('adzuna 429', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function missing_credentials_short_circuits_with_error_result(): void
    {
        config(['scrapers.aggregators.adzuna.app_id' => '']);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(
            'Adzuna credentials not configured (set ADZUNA_APP_ID/ADZUNA_APP_KEY).',
            $result->errors[0]['message'],
        );
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
