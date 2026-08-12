<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Rippling\Requests\BoardJobsRequest;
use App\Scrapers\Rippling\RipplingScraper;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class RipplingScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private RipplingScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::RIPPLING->value,
            'ats_slug' => 'acme',
        ]);

        $this->scraper = new RipplingScraper(new RoleDiffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name = 'board_jobs_happy.json'): array
    {
        $path = __DIR__.'/../../Fixtures/scrapers/rippling/'.$name;

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_inserts_roles_emits_new_events_and_captures_etag(): void
    {
        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(
                body: $this->fixture(),
                status: 200,
                headers: [
                    'ETag' => '"abc-123"',
                    'Last-Modified' => 'Mon, 12 May 2026 10:00:00 GMT',
                ],
            ),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertSame('"abc-123"', $result->etag);
        $this->assertSame('Mon, 12 May 2026 10:00:00 GMT', $result->lastModified);

        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(3, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        $fresh = $this->company->fresh();
        $this->assertSame('"abc-123"', $fresh->etag);
        $this->assertSame('Mon, 12 May 2026 10:00:00 GMT', $fresh->last_modified);
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function not_modified_response_short_circuits_and_updates_last_checked_at(): void
    {
        $this->company->etag = '"prev-tag"';
        $this->company->last_modified = 'Sun, 11 May 2026 10:00:00 GMT';
        $this->company->save();

        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(body: '', status: 304),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->notModified);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, Role::query()->count());
        $this->assertSame(0, Event::query()->count());

        $fresh = $this->company->fresh();
        $this->assertSame('"prev-tag"', $fresh->etag);
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);
        $this->assertSame(3, Event::query()->count());

        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $second = $this->scraper->run($this->company);

        $this->assertSame(0, $second->newCount);
        $this->assertSame(0, $second->closedCount);
        $this->assertSame(3, $second->fetched);
        $this->assertSame(3, Event::query()->count(), 'Re-running must not duplicate events.');
    }

    #[Test]
    public function exception_during_fetch_returns_result_with_errors(): void
    {
        Saloon::fake([
            BoardJobsRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('boom');
            },
        ]);

        $originalEtag = $this->company->etag;
        $originalLastChecked = $this->company->last_checked_at;

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, $result->closedCount);

        $fresh = $this->company->fresh();
        $this->assertSame($originalEtag, $fresh->etag);
        $this->assertEquals($originalLastChecked, $fresh->last_checked_at);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function malformed_payload_missing_items_key_returns_error_without_touching_roles(): void
    {
        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(body: ['notItems' => []], status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function remote_location_name_sets_workplace_type_and_is_remote(): void
    {
        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $remote = Role::query()->where('external_id', '11111111-1111-1111-1111-111111111003')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);

        $onsite = Role::query()->where('external_id', '11111111-1111-1111-1111-111111111001')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::UNSPECIFIED, $onsite->workplace_type);
    }

    #[Test]
    public function zero_fetch_with_open_roles_refuses_to_mass_close(): void
    {
        $openRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => AtsType::RIPPLING->value,
            'is_open' => true,
        ]);

        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(
                body: ['items' => [], 'page' => 0, 'pageSize' => 100, 'totalItems' => 0, 'totalPages' => 0],
                status: 200,
            ),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->closedCount);
        $this->assertTrue($openRole->fresh()->is_open);
    }

    #[Test]
    public function pagination_accumulates_roles_across_two_pages(): void
    {
        Saloon::fake([
            MockResponse::make($this->fixture('board_jobs_page_1.json'), 200),
            MockResponse::make($this->fixture('board_jobs_page_2.json'), 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());

        // Page 2's role is present, proving the walk didn't stop after page 1.
        $this->assertTrue(
            Role::query()->where('external_id', '22222222-2222-2222-2222-222222222003')->exists(),
        );
    }

    #[Test]
    public function hitting_the_page_cap_with_more_pages_remaining_reports_truncation(): void
    {
        // Every page reports a full 100-row page and totalPages claims far
        // more pages exist than the 10-page cap can ever reach.
        $page = [
            'items' => array_map(
                static fn (int $i): array => ['id' => "rip-{$i}", 'name' => "Role {$i}"],
                range(1, 100),
            ),
            'page' => 0,
            'pageSize' => 100,
            'totalItems' => 5000,
            'totalPages' => 50,
        ];

        Saloon::fake([
            BoardJobsRequest::class => MockResponse::make(body: $page, status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors(), 'A truncated fetch must surface as a run error, not a clean completion.');
        $this->assertSame(1000, $result->fetched);
        $this->assertStringContainsString('truncated', $result->errors[0]['message']);
    }
}
