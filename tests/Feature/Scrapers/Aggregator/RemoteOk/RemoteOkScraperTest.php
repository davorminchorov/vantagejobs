<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\RemoteOk;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\RemoteOk\RemoteOkScraper;
use App\Scrapers\Aggregator\RemoteOk\Requests\JobsRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class RemoteOkScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private RemoteOkScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();
        $this->scraper = $this->app->make(RemoteOkScraper::class);
    }

    /**
     * @return array<int, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/remoteok/jobs.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_persists_each_job_skipping_the_metadata_element(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        // 3 jobs persisted — confirms the metadata at index 0 was skipped
        // (a naive iteration would have produced 4 fetched, 0+ errors).
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        $acme = AggregatorListing::query()->where('external_id', 'rok-90001')->firstOrFail();
        $this->assertSame('Acme Corp', $acme->parsed_company_name);
        $this->assertSame('Senior Backend Engineer', $acme->parsed_role_title);
        $this->assertSame('senior', $acme->parsed_seniority);
        $this->assertSame(150000, $acme->parsed_comp_min_usd);
        $this->assertSame(200000, $acme->parsed_comp_max_usd);
        $this->assertTrue($acme->has_comp);
        // RemoteOK salary fields are treated as USD by convention per SOURCES.md §2.5.
        $this->assertSame('USD', $acme->parsed['compensation']['value']['currency']);
        $this->assertContains('python', $acme->stack_tags);
        $this->assertContains('django', $acme->stack_tags);
        $this->assertSame('acme', $acme->normalized_company_key);
        $this->assertSame(AggregatorListingStatus::NEW, $acme->status);
        $this->assertSame(
            'https://remoteok.com/remote-jobs/rok-90001',
            $acme->url,
        );

        // No row should carry the metadata envelope's keys as an external_id.
        $this->assertSame(
            0,
            AggregatorListing::query()->where('external_id', 'legal')->count(),
        );
    }

    #[Test]
    public function crypto_role_persists_with_solidity_tags(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $this->scraper->run($this->source);

        $blockchainery = AggregatorListing::query()->where('external_id', 'rok-90002')->firstOrFail();

        // The scraper just persists — UI-side filtering by tag is downstream.
        $this->assertSame('Blockchainery', $blockchainery->parsed_company_name);
        $this->assertSame('Senior Solidity Engineer', $blockchainery->parsed_role_title);
        $this->assertNotNull($blockchainery->stack_tags);
        $this->assertContains('solidity', $blockchainery->stack_tags);
        $this->assertSame(180000, $blockchainery->parsed_comp_min_usd);
        $this->assertSame(260000, $blockchainery->parsed_comp_max_usd);
    }

    #[Test]
    public function go_kubernetes_role_picks_up_emea_region(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $this->scraper->run($this->source);

        $flux = AggregatorListing::query()->where('external_id', 'rok-90003')->firstOrFail();
        $this->assertContains('go', $flux->stack_tags);
        $this->assertContains('kubernetes', $flux->stack_tags);
        $this->assertSame('emea', $flux->location_bucket);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
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
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->where('external_id', 'rok-90001')->firstOrFail();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();

        Saloon::fake([
            JobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', 'rok-90001')->firstOrFail()->status,
        );
    }

    #[Test]
    public function metadata_at_index_zero_is_explicitly_skipped(): void
    {
        // Per SOURCES.md §2.5, the real legal/metadata envelope carries no
        // `id` key at all - that absence is what the scraper keys off.
        $payload = [
            ['legal' => 'Terms apply', 'warning' => 'Sponsored postings appear first'],
            [
                'id' => 'rok-77777',
                'company' => 'Solo Corp',
                'position' => 'Backend Engineer',
                'tags' => ['python'],
                'description' => '<p>A backend role.</p>',
                'location' => 'Worldwide',
                'salary_min' => 120000,
                'salary_max' => 160000,
                'apply_url' => 'https://remoteok.com/remote-jobs/rok-77777/apply',
                'url' => 'https://remoteok.com/remote-jobs/rok-77777',
                'date' => '2026-05-13T12:00:00+00:00',
            ],
        ];

        Saloon::fake([
            JobsRequest::class => MockResponse::make($payload, 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->newCount);
        $this->assertSame(1, AggregatorListing::query()->count());

        $row = AggregatorListing::query()->firstOrFail();
        $this->assertSame('rok-77777', $row->external_id);
    }

    #[Test]
    public function a_real_job_at_index_zero_is_processed_not_dropped(): void
    {
        // If RemoteOK ever ships the envelope without the metadata element,
        // a real job landing at index 0 (it has an `id`) must not be
        // silently discarded just because of its position in the array.
        $payload = [
            [
                'id' => 'rok-88888',
                'company' => 'No Envelope Inc',
                'position' => 'Frontend Engineer',
                'tags' => ['react'],
                'description' => '<p>A frontend role.</p>',
                'location' => 'Worldwide',
                'apply_url' => 'https://remoteok.com/remote-jobs/rok-88888/apply',
                'url' => 'https://remoteok.com/remote-jobs/rok-88888',
                'date' => '2026-05-13T12:00:00+00:00',
            ],
        ];

        Saloon::fake([
            JobsRequest::class => MockResponse::make($payload, 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->newCount);
        $this->assertSame('rok-88888', AggregatorListing::query()->firstOrFail()->external_id);
    }

    #[Test]
    public function double_encoded_utf8_from_the_upstream_api_is_repaired_before_persisting(): void
    {
        // RemoteOK occasionally serves double-encoded UTF-8 (see
        // MojibakeRepairer) - confirmed against the live API on real rows
        // like "Universia MÃ©xico" / "MÃ©xico" instead of "México".
        $payload = [
            [
                'id' => 'rok-99001',
                'company' => 'Universia MÃ©xico',
                'position' => 'Auxiliar de OperaciÃ³n',
                'tags' => ['soporte'],
                'description' => '<p>RÃ³l de soporte remoto.</p>',
                'location' => 'CoyoacÃ¡n, Ciudad de MÃ©xico',
                'apply_url' => 'https://remoteok.com/remote-jobs/rok-99001/apply',
                'url' => 'https://remoteok.com/remote-jobs/rok-99001',
                'date' => '2026-05-13T12:00:00+00:00',
            ],
        ];

        Saloon::fake([
            JobsRequest::class => MockResponse::make($payload, 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());

        $row = AggregatorListing::query()->where('external_id', 'rok-99001')->firstOrFail();
        $this->assertSame('Universia México', $row->parsed_company_name);
        $this->assertSame('Auxiliar de Operación', $row->parsed_role_title);
        $this->assertSame('Coyoacán, Ciudad de México', $row->raw['location']);
        $this->assertSame('Ról de soporte remoto.', trim(strip_tags((string) $row->raw['description'])));
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            JobsRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('remoteok 503');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('remoteok 503', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function http_error_status_is_recorded_without_persisting(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: 'Service Unavailable', status: 500),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(500, $result->errors[0]['context']['http_status']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
