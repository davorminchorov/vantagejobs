<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Lever\LeverConnector;
use App\Scrapers\Lever\LeverScraper;
use App\Scrapers\Lever\Requests\PostingsRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

final class LeverScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => 'lever',
            'ats_slug' => 'acme',
            'etag' => null,
            'last_modified' => null,
            'last_checked_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();
        parent::tearDown();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function happyFixture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/scrapers/lever/postings_happy.json'),
            true,
        );
    }

    private function scraper(): LeverScraper
    {
        return $this->app->make(LeverScraper::class);
    }

    #[Test]
    public function happy_path_inserts_roles_and_maps_workplace_type_variants(): void
    {
        MockClient::global([
            PostingsRequest::class => MockResponse::make($this->happyFixture(), 200, [
                'ETag' => '"abc"',
                'Last-Modified' => 'Wed, 21 Oct 2025 07:28:00 GMT',
            ]),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertSame(4, $result->fetched);
        $this->assertSame(4, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertSame([], $result->errors);
        $this->assertFalse($result->notModified);
        $this->assertSame('"abc"', $result->etag);
        $this->assertSame('Wed, 21 Oct 2025 07:28:00 GMT', $result->lastModified);

        $this->assertSame(WorkplaceType::REMOTE, Role::query()->where('external_id', 'lever-remote-1')->firstOrFail()->workplace_type);
        $this->assertSame(WorkplaceType::ON_SITE, Role::query()->where('external_id', 'lever-onsite-1')->firstOrFail()->workplace_type);
        $this->assertSame(WorkplaceType::HYBRID, Role::query()->where('external_id', 'lever-hybrid-1')->firstOrFail()->workplace_type);
        $this->assertSame(WorkplaceType::UNSPECIFIED, Role::query()->where('external_id', 'lever-unspec-1')->firstOrFail()->workplace_type);

        $this->assertTrue((bool) Role::query()->where('external_id', 'lever-remote-1')->firstOrFail()->is_remote);
        $this->assertFalse((bool) Role::query()->where('external_id', 'lever-onsite-1')->firstOrFail()->is_remote);

        $company = $this->company->fresh();
        $this->assertSame('"abc"', $company->etag);
        $this->assertSame('Wed, 21 Oct 2025 07:28:00 GMT', $company->last_modified);
        $this->assertNotNull($company->last_checked_at);
    }

    #[Test]
    public function salary_range_is_populated_when_present(): void
    {
        MockClient::global([
            PostingsRequest::class => MockResponse::make($this->happyFixture(), 200),
        ]);

        $this->scraper()->run($this->company);

        $role = Role::query()->where('external_id', 'lever-remote-1')->firstOrFail();
        $this->assertSame(180000, $role->comp_min);
        $this->assertSame(240000, $role->comp_max);
        $this->assertSame('USD', $role->comp_currency);
        $this->assertSame('per-year', $role->comp_interval);
    }

    #[Test]
    public function salary_range_is_null_when_missing(): void
    {
        MockClient::global([
            PostingsRequest::class => MockResponse::make($this->happyFixture(), 200),
        ]);

        $this->scraper()->run($this->company);

        $role = Role::query()->where('external_id', 'lever-onsite-1')->firstOrFail();
        $this->assertNull($role->comp_min);
        $this->assertNull($role->comp_max);
        $this->assertNull($role->comp_currency);
        $this->assertNull($role->comp_interval);
    }

    #[Test]
    public function created_at_milliseconds_is_converted_to_carbon_immutable(): void
    {
        MockClient::global([
            PostingsRequest::class => MockResponse::make($this->happyFixture(), 200),
        ]);

        $this->scraper()->run($this->company);

        $role = Role::query()->where('external_id', 'lever-remote-1')->firstOrFail();
        // 1715000000000 ms = 2024-05-06 14:13:20 UTC
        $expected = CarbonImmutable::createFromTimestampMs(1715000000000);
        $this->assertSame($expected->getTimestamp(), $role->posted_at->getTimestamp());
    }

    #[Test]
    public function not_modified_short_circuits_and_does_not_touch_roles(): void
    {
        $this->company->etag = '"abc"';
        $this->company->last_modified = 'Wed, 21 Oct 2025 07:28:00 GMT';
        $this->company->save();

        MockClient::global([
            PostingsRequest::class => MockResponse::make([], 304),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertTrue($result->notModified);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertSame('"abc"', $result->etag);
        $this->assertSame('Wed, 21 Oct 2025 07:28:00 GMT', $result->lastModified);
        $this->assertSame(0, Role::query()->count());

        $this->assertNotNull($this->company->fresh()->last_checked_at);
    }

    #[Test]
    public function second_run_against_identical_fixture_is_idempotent(): void
    {
        MockClient::global([
            PostingsRequest::class => MockResponse::make($this->happyFixture(), 200),
        ]);

        $first = $this->scraper()->run($this->company);
        $this->assertSame(4, $first->newCount);
        $this->assertSame(4, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        // Fresh global mock client for second run.
        MockClient::destroyGlobal();
        MockClient::global([
            PostingsRequest::class => MockResponse::make($this->happyFixture(), 200),
        ]);

        $second = $this->scraper()->run($this->company);

        $this->assertSame(4, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(0, $second->closedCount);
        $this->assertSame(4, Event::query()->count(), 'Second run must not duplicate events.');
    }

    #[Test]
    public function exception_during_send_returns_error_result_and_preserves_company_state(): void
    {
        // No mock client registered → Saloon will throw on send.
        $this->company->etag = '"unchanged"';
        $this->company->last_modified = 'Mon, 01 Jan 2024 00:00:00 GMT';
        $this->company->save();

        // Force a failure by stubbing the connector with a closure that throws.
        $this->app->bind(LeverConnector::class, function () {
            $connector = new LeverConnector($this->app->make('config'));
            $connector->withMockClient(new MockClient([
                PostingsRequest::class => function (): never {
                    throw new \RuntimeException('boom');
                },
            ]));

            return $connector;
        });

        $result = $this->scraper()->run($this->company);

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertNotEmpty($result->errors);
        $this->assertSame('boom', $result->errors[0]['message']);

        $company = $this->company->fresh();
        $this->assertSame('"unchanged"', $company->etag);
        $this->assertSame('Mon, 01 Jan 2024 00:00:00 GMT', $company->last_modified);
    }
}
