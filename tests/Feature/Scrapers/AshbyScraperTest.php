<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Ashby\AshbyScraper;
use App\Scrapers\Ashby\Requests\JobBoardRequest;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class AshbyScraperTest extends TestCase
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
            'ats_type' => 'ashby',
            'ats_slug' => 'acme',
        ]);
    }

    private function scraper(): AshbyScraper
    {
        return new AshbyScraper(
            new RoleDiffer(),
            $this->app->make(\Illuminate\Contracts\Config\Repository::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function happyFixture(): array
    {
        $path = base_path('tests/Fixtures/scrapers/ashby/job_board_happy.json');

        return json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function maps_jobs_with_each_workplace_type_variant_into_parsed_roles(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(
                body: $this->happyFixture(),
                status: 200,
                headers: ['ETag' => 'W/"abc"', 'Last-Modified' => 'Wed, 13 May 2026 10:00:00 GMT'],
            ),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertSame(5, $result->fetched);
        $this->assertSame(5, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertFalse($result->notModified);
        $this->assertSame('W/"abc"', $result->etag);
        $this->assertSame('Wed, 13 May 2026 10:00:00 GMT', $result->lastModified);

        $remote = Role::query()->where('external_id', 'ashby-uuid-remote')->firstOrFail();
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);
        $this->assertSame('Engineering', $remote->department);
        $this->assertSame('Platform', $remote->team);
        $this->assertSame('https://jobs.ashbyhq.com/acme/ashby-uuid-remote', $remote->url);

        $onsite = Role::query()->where('external_id', 'ashby-uuid-onsite')->firstOrFail();
        $this->assertSame(WorkplaceType::ON_SITE, $onsite->workplace_type);

        $hybrid = Role::query()->where('external_id', 'ashby-uuid-hybrid')->firstOrFail();
        $this->assertSame(WorkplaceType::HYBRID, $hybrid->workplace_type);

        $unspecified = Role::query()->where('external_id', 'ashby-uuid-unspecified')->firstOrFail();
        $this->assertSame(WorkplaceType::UNSPECIFIED, $unspecified->workplace_type);

        // Missing workplaceType but isRemote=true → REMOTE
        $missing = Role::query()->where('external_id', 'ashby-uuid-missing-workplace')->firstOrFail();
        $this->assertSame(WorkplaceType::REMOTE, $missing->workplace_type);

        $this->company->refresh();
        $this->assertSame('W/"abc"', $this->company->etag);
        $this->assertSame('Wed, 13 May 2026 10:00:00 GMT', $this->company->last_modified);
        $this->assertNotNull($this->company->last_checked_at);
    }

    #[Test]
    public function picks_highest_paying_salary_tier_for_compensation_fields(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: $this->happyFixture(), status: 200),
        ]);

        $this->scraper()->run($this->company);

        $hybrid = Role::query()->where('external_id', 'ashby-uuid-hybrid')->firstOrFail();

        // US tier maxes at 220000 (USD), EU tier at 180000 (EUR). Pick USD.
        $this->assertSame(180000, $hybrid->comp_min);
        $this->assertSame(220000, $hybrid->comp_max);
        $this->assertSame('USD', $hybrid->comp_currency);
        $this->assertSame('1 YEAR', $hybrid->comp_interval);
    }

    #[Test]
    public function leaves_comp_fields_null_when_no_salary_component_anywhere(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: $this->happyFixture(), status: 200),
        ]);

        $this->scraper()->run($this->company);

        // Commission-only tier: no salary component → null comp fields.
        $missing = Role::query()->where('external_id', 'ashby-uuid-missing-workplace')->firstOrFail();
        $this->assertNull($missing->comp_min);
        $this->assertNull($missing->comp_max);
        $this->assertNull($missing->comp_currency);
        $this->assertNull($missing->comp_interval);

        // Onsite has tiers: [] — also null.
        $onsite = Role::query()->where('external_id', 'ashby-uuid-onsite')->firstOrFail();
        $this->assertNull($onsite->comp_max);
    }

    #[Test]
    public function not_modified_response_short_circuits_and_updates_last_checked_at(): void
    {
        $this->company->forceFill([
            'etag' => 'W/"prev"',
            'last_modified' => 'Mon, 01 May 2026 00:00:00 GMT',
        ])->save();

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: '', status: 304),
        ]);

        $before = $this->company->last_checked_at;
        $result = $this->scraper()->run($this->company);

        $this->assertTrue($result->notModified);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame('W/"prev"', $result->etag);
        $this->assertSame(0, Role::query()->count(), 'No roles touched on 304.');
        $this->assertSame(0, Event::query()->count());

        $this->company->refresh();
        $this->assertNotEquals($before, $this->company->last_checked_at);
    }

    #[Test]
    public function idempotent_second_run_emits_no_new_events(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: $this->happyFixture(), status: 200),
        ]);

        $this->scraper()->run($this->company);
        $eventsAfterFirst = Event::query()->count();
        $this->assertSame(5, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        // Re-fake so the mock queue has a second response.
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: $this->happyFixture(), status: 200),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertSame(5, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertSame($eventsAfterFirst, Event::query()->count(), 'Second run must not duplicate events.');
    }

    #[Test]
    public function exception_during_run_returns_error_result_with_zero_counts(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: 'oops', status: 500),
        ]);

        $originalEtag = $this->company->etag;
        $originalLastChecked = $this->company->last_checked_at;

        $result = $this->scraper()->run($this->company);

        $this->assertNotSame([], $result->errors);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, $result->closedCount);
        $this->assertSame(0, Role::query()->count());
        $this->assertSame(0, Event::query()->count());

        $this->company->refresh();
        $this->assertSame($originalEtag, $this->company->etag);
        $this->assertEquals($originalLastChecked, $this->company->last_checked_at);
    }

    #[Test]
    public function does_not_close_roles_that_belong_to_a_different_source(): void
    {
        // Regression for the bug where Ashby diffed against every Role on
        // the company (no `source` scope), so a stale Greenhouse-sourced
        // role would get swept into Ashby's "existing" set and closed even
        // though Ashby's fetch never mentioned it.
        $foreignRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => AtsType::GREENHOUSE->value,
            'external_id' => 'gh-123',
            'is_open' => true,
        ]);

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: $this->happyFixture(), status: 200),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertSame(0, $result->closedCount);
        $this->assertTrue($foreignRole->fresh()->is_open);
    }

    #[Test]
    public function malformed_payload_missing_jobs_key_returns_error_without_touching_roles(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: ['notJobs' => []], status: 200),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertNotSame([], $result->errors);
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function zero_fetch_with_open_roles_refuses_to_mass_close(): void
    {
        $openRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => AtsType::ASHBY->value,
            'external_id' => 'ashby-uuid-remote',
            'is_open' => true,
        ]);

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(body: ['jobs' => []], status: 200),
        ]);

        $result = $this->scraper()->run($this->company);

        $this->assertNotSame([], $result->errors);
        $this->assertSame(0, $result->closedCount);
        $this->assertTrue($openRole->fresh()->is_open);
    }
}
