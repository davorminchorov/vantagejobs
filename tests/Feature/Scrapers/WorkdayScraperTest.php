<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\RoleDiffer;
use App\Scrapers\Workday\Requests\JobsRequest;
use App\Scrapers\Workday\WorkdayScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class WorkdayScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private WorkdayScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::WORKDAY->value,
            'ats_slug' => 'acme/careers',
            'ats_host' => 'acme.wd5.myworkdayjobs.com',
        ]);

        $this->scraper = new WorkdayScraper(new RoleDiffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name = 'jobs_page.json'): array
    {
        $path = __DIR__.'/../../Fixtures/scrapers/workday/'.$name;

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_inserts_roles_and_emits_new_events(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(0, $result->closedCount);

        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(3, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        $fresh = $this->company->fresh();
        $this->assertSame('acme.wd5.myworkdayjobs.com', $fresh->ats_host);
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function requisition_id_becomes_external_id_and_url_is_constructed_from_host_and_site(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $role = Role::query()->where('external_id', 'JR2020627')->firstOrFail();
        $this->assertSame(
            'https://acme.wd5.myworkdayjobs.com/en-US/careers/job/Israel-Yokneam/Chip-Design-Engineer_JR2020627',
            $role->url,
        );
    }

    #[Test]
    public function relative_posted_on_strings_parse_to_approximate_dates(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $today = Role::query()->where('external_id', 'JR2020627')->firstOrFail();
        $this->assertNotNull($today->posted_at);
        $this->assertTrue($today->posted_at->isSameDay(now()));

        $yesterday = Role::query()->where('external_id', 'JR2023102')->firstOrFail();
        $this->assertNotNull($yesterday->posted_at);
        $this->assertTrue($yesterday->posted_at->isSameDay(now()->subDay()));

        $fiveDays = Role::query()->where('external_id', 'JR2001201')->firstOrFail();
        $this->assertNotNull($fiveDays->posted_at);
        $this->assertTrue($fiveDays->posted_at->isSameDay(now()->subDays(5)));
    }

    #[Test]
    public function remote_locations_text_sets_workplace_type_and_is_remote(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $remote = Role::query()->where('external_id', 'JR2001201')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);

        $onsite = Role::query()->where('external_id', 'JR2023102')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::UNSPECIFIED, $onsite->workplace_type);
    }

    #[Test]
    public function unknown_host_is_probed_and_persisted(): void
    {
        $this->company->ats_host = null;
        $this->company->save();

        // wd1 misses, wd2 hits: the scraper must persist the wd2 host.
        Saloon::fake([
            MockResponse::make(body: '', status: 404),
            MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame('acme.wd2.myworkdayjobs.com', $this->company->fresh()->ats_host);
    }

    #[Test]
    public function stale_cached_host_falls_back_to_probing(): void
    {
        // The cached wd5 host 404s; the wd1 probe hits and replaces it.
        Saloon::fake([
            MockResponse::make(body: '', status: 404),
            MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame('acme.wd1.myworkdayjobs.com', $this->company->fresh()->ats_host);
    }

    #[Test]
    public function wrong_pod_422_is_treated_as_a_miss_during_probing(): void
    {
        // Live behavior: a pod that doesn't host the tenant answers 422, not
        // 404. Cached wd5 host 422s, the wd1 probe 422s, wd2 hits.
        Saloon::fake([
            MockResponse::make(body: '', status: 422),
            MockResponse::make(body: '', status: 422),
            MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame('acme.wd2.myworkdayjobs.com', $this->company->fresh()->ats_host);
    }

    #[Test]
    public function exhausting_every_pod_reports_slug_not_found(): void
    {
        $this->company->ats_host = null;
        $this->company->save();

        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: '', status: 404),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(404, $result->errors[0]['context']['http_status'] ?? null);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function malformed_slug_without_site_part_returns_error_without_any_request(): void
    {
        $this->company->ats_slug = 'acme';
        $this->company->save();

        Saloon::fake([]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('tenant/site', $result->errors[0]['message']);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
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
            JobsRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('connection reset');
            },
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('connection reset', $result->errors[0]['message']);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function malformed_payload_missing_job_postings_key_returns_error_without_touching_roles(): void
    {
        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: ['unexpected' => true], status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('malformed payload', $result->errors[0]['message']);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function zero_fetch_with_open_roles_refuses_to_mass_close(): void
    {
        $openRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => AtsType::WORKDAY->value,
            'is_open' => true,
        ]);

        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: ['total' => 0, 'jobPostings' => []], status: 200),
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
            MockResponse::make($this->fixture('jobs_page_1.json'), 200),
            MockResponse::make($this->fixture('jobs_page_2.json'), 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(25, $result->fetched);
        $this->assertSame(25, $result->newCount);

        // Page 2's roles are present, proving the walk didn't stop after page 1.
        $this->assertTrue(Role::query()->where('external_id', 'JR300025')->exists());
    }

    #[Test]
    public function zero_total_on_later_pages_does_not_stop_the_walk_early(): void
    {
        // Live behavior: only the offset-0 page carries the real `total`;
        // later pages report `total: 0` while still returning postings.
        $makePage = static function (int $from, int $count, int $total): array {
            return [
                'total' => $total,
                'jobPostings' => array_map(static fn (int $i): array => [
                    'title' => "Engineer {$i}",
                    'externalPath' => "/job/Somewhere/Engineer_{$i}",
                    'locationsText' => 'Somewhere',
                    'postedOn' => 'Posted Today',
                    'bulletFields' => ["JR9{$i}"],
                ], range($from, $from + $count - 1)),
            ];
        };

        Saloon::fake([
            MockResponse::make($makePage(1, 20, 45), 200),
            MockResponse::make($makePage(21, 20, 0), 200),
            MockResponse::make($makePage(41, 5, 0), 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(45, $result->fetched, 'The walk must continue past a later page reporting total 0.');
    }

    #[Test]
    public function hitting_the_page_cap_with_more_postings_remaining_reports_truncation(): void
    {
        // Every page reports a full 20-row page while `total` claims far more
        // postings exist than the 100-page cap can ever reach.
        $page = $this->fixture('jobs_page_1.json');
        $page['total'] = 5000;

        Saloon::fake([
            JobsRequest::class => MockResponse::make(body: $page, status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors(), 'A truncated fetch must surface as a run error, not a clean completion.');
        $this->assertSame(2000, $result->fetched);
        $this->assertStringContainsString('truncated', $result->errors[0]['message']);
    }
}
