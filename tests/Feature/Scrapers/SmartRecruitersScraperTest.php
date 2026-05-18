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
use App\Scrapers\SmartRecruiters\Requests\CompanyPostingsRequest;
use App\Scrapers\SmartRecruiters\SmartRecruitersScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class SmartRecruitersScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private SmartRecruitersScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::SMARTRECRUITERS->value,
            'ats_slug' => 'acmecorp',
        ]);

        $this->scraper = new SmartRecruitersScraper(new RoleDiffer());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../Fixtures/scrapers/smartrecruiters/postings_happy.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_inserts_roles_and_emits_new_events(): void
    {
        Saloon::fake([
            CompanyPostingsRequest::class => MockResponse::make(
                body: $this->fixture(),
                status: 200,
            ),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(0, $result->closedCount);

        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(3, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        $fresh = $this->company->fresh();
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function remote_flag_in_location_sets_workplace_type_and_is_remote(): void
    {
        Saloon::fake([
            CompanyPostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $remote = Role::query()->where('external_id', 'sr-9003')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);

        $onsite = Role::query()->where('external_id', 'sr-9001')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::UNSPECIFIED, $onsite->workplace_type);
    }

    #[Test]
    public function url_is_constructed_from_slug_and_posting_id(): void
    {
        Saloon::fake([
            CompanyPostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $role = Role::query()->where('external_id', 'sr-9001')->firstOrFail();
        $this->assertSame('https://jobs.smartrecruiters.com/acmecorp/sr-9001', $role->url);
    }

    #[Test]
    public function department_falls_back_to_function_label_when_missing(): void
    {
        Saloon::fake([
            CompanyPostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $designer = Role::query()->where('external_id', 'sr-9002')->firstOrFail();
        $this->assertSame('Design', $designer->department);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            CompanyPostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            CompanyPostingsRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
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
            CompanyPostingsRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('boom');
            },
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, Role::query()->count());
    }
}
