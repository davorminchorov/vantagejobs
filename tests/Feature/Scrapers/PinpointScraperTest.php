<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Pinpoint\PinpointScraper;
use App\Scrapers\Pinpoint\Requests\PostingsRequest;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class PinpointScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private PinpointScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::PINPOINT->value,
            'ats_slug' => 'workwithus',
        ]);

        $this->scraper = new PinpointScraper(new RoleDiffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../Fixtures/scrapers/pinpoint/postings_happy.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_inserts_roles_emits_new_events_and_captures_etag(): void
    {
        Saloon::fake([
            PostingsRequest::class => MockResponse::make(
                body: $this->fixture(),
                status: 200,
                headers: [
                    'ETag' => 'W/"abc-123"',
                    'Last-Modified' => 'Mon, 12 May 2026 10:00:00 GMT',
                ],
            ),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertSame('W/"abc-123"', $result->etag);
        $this->assertSame('Mon, 12 May 2026 10:00:00 GMT', $result->lastModified);

        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(3, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        $fresh = $this->company->fresh();
        $this->assertSame('W/"abc-123"', $fresh->etag);
        $this->assertSame('Mon, 12 May 2026 10:00:00 GMT', $fresh->last_modified);
        $this->assertNotNull($fresh->last_checked_at);

        $visibleComp = Role::query()->where('external_id', '520855')->firstOrFail();
        $this->assertSame(60000, $visibleComp->comp_min);
        $this->assertSame(70000, $visibleComp->comp_max);
        $this->assertSame('GBP', $visibleComp->comp_currency);
        $this->assertSame('year', $visibleComp->comp_interval);

        $hiddenComp = Role::query()->where('external_id', '559663')->firstOrFail();
        $this->assertNull($hiddenComp->comp_min);
        $this->assertNull($hiddenComp->comp_max);
        $this->assertNull($hiddenComp->comp_currency);
        $this->assertNull($hiddenComp->comp_interval);
    }

    #[Test]
    public function not_modified_response_short_circuits_and_updates_last_checked_at(): void
    {
        $this->company->etag = 'W/"prev-tag"';
        $this->company->last_modified = 'Sun, 11 May 2026 10:00:00 GMT';
        $this->company->save();

        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: '', status: 304),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->notModified);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, Role::query()->count());
        $this->assertSame(0, Event::query()->count());

        $fresh = $this->company->fresh();
        $this->assertSame('W/"prev-tag"', $fresh->etag);
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);
        $this->assertSame(3, Event::query()->count());

        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
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
            PostingsRequest::class => function (PendingRequest $request): MockResponse {
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
    public function http_error_status_is_captured_in_error_context(): void
    {
        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: '', status: 500),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(500, $result->errors[0]['context']['http_status']);
    }

    #[Test]
    public function workplace_type_field_maps_to_role_workplace_type_and_is_remote(): void
    {
        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $remote = Role::query()->where('external_id', '520855')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);
        $this->assertSame('Remote', $remote->location);

        $onsite = Role::query()->where('external_id', '600301')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::ON_SITE, $onsite->workplace_type);
        // location.name is null on this fixture entry, so parsing falls back
        // to location.city.
        $this->assertSame('Austin', $onsite->location);
        $this->assertSame(50000, $onsite->comp_min);
        $this->assertSame(60000, $onsite->comp_max);
        $this->assertSame('USD', $onsite->comp_currency);
    }

    #[Test]
    public function unrecognized_workplace_type_falls_back_to_unspecified_and_location_string_match(): void
    {
        Saloon::fake([
            PostingsRequest::class => MockResponse::make(
                body: [
                    'data' => [[
                        'id' => '700001',
                        'title' => 'Field Technician',
                        'url' => 'https://workwithus.pinpointhq.com/en/postings/field-technician',
                        'workplace_type' => 'flexible',
                        'location' => ['name' => 'Remote - EU'],
                        'compensation_visible' => false,
                        'job' => ['department' => ['name' => 'Support']],
                    ]],
                ],
                status: 200,
            ),
        ]);

        $this->scraper->run($this->company);

        $role = Role::query()->where('external_id', '700001')->firstOrFail();
        $this->assertSame(WorkplaceType::UNSPECIFIED, $role->workplace_type);
        $this->assertTrue((bool) $role->is_remote);
    }

    #[Test]
    public function malformed_payload_missing_data_key_returns_error_without_touching_roles(): void
    {
        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: ['notData' => []], status: 200),
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
            'source' => AtsType::PINPOINT->value,
            'is_open' => true,
        ]);

        Saloon::fake([
            PostingsRequest::class => MockResponse::make(body: ['data' => []], status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->closedCount);
        $this->assertTrue($openRole->fresh()->is_open);
    }
}
