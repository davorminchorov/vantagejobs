<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\BambooHr\BambooHrScraper;
use App\Scrapers\BambooHr\Requests\CareersListRequest;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class BambooHrScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private BambooHrScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::BAMBOOHR->value,
            'ats_slug' => 'acme',
        ]);

        $this->scraper = new BambooHrScraper(new RoleDiffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../Fixtures/scrapers/bamboohr/careers_list_happy.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_inserts_roles_emits_new_events_and_captures_etag(): void
    {
        Saloon::fake([
            CareersListRequest::class => MockResponse::make(
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

        $role = Role::query()->where('external_id', '343')->firstOrFail();
        $this->assertSame('https://acme.bamboohr.com/careers/343', $role->url);

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
            CareersListRequest::class => MockResponse::make(body: '', status: 304),
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
            CareersListRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);
        $this->assertSame(3, Event::query()->count());

        Saloon::fake([
            CareersListRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
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
            CareersListRequest::class => function (PendingRequest $request): MockResponse {
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
    public function remote_locations_are_mapped_and_onsite_locations_are_not(): void
    {
        Saloon::fake([
            CareersListRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $remote = Role::query()->where('external_id', '356')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);
        $this->assertSame('United Kingdom', $remote->location);

        $onsite = Role::query()->where('external_id', '343')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::UNSPECIFIED, $onsite->workplace_type);
        $this->assertSame('Stavanger', $onsite->location);

        $onsiteWithState = Role::query()->where('external_id', '348')->firstOrFail();
        $this->assertFalse((bool) $onsiteWithState->is_remote);
        $this->assertSame('Houston, Texas', $onsiteWithState->location);
    }

    #[Test]
    public function malformed_payload_missing_result_key_returns_error_without_touching_roles(): void
    {
        Saloon::fake([
            CareersListRequest::class => MockResponse::make(body: ['notResult' => []], status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function zero_fetch_with_open_roles_refuses_to_mass_close(): void
    {
        $openRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => AtsType::BAMBOOHR->value,
            'is_open' => true,
        ]);

        Saloon::fake([
            CareersListRequest::class => MockResponse::make(body: ['meta' => ['totalCount' => 0], 'result' => []], status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->closedCount);
        $this->assertTrue($openRole->fresh()->is_open);
    }

    #[Test]
    public function redirect_to_marketing_site_maps_to_404_and_writes_zero_roles(): void
    {
        Saloon::fake([
            CareersListRequest::class => MockResponse::make(body: '', status: 302, headers: [
                'Location' => 'https://www.bamboohr.com',
            ]),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(404, $result->errors[0]['context']['http_status']);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }
}
