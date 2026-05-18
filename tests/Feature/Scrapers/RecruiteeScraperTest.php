<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Recruitee\Requests\OffersRequest;
use App\Scrapers\Recruitee\RecruiteeScraper;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class RecruiteeScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private RecruiteeScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::RECRUITEE->value,
            'ats_slug' => 'acme',
        ]);

        $this->scraper = new RecruiteeScraper(new RoleDiffer());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return [
            'offers' => [
                [
                    'id' => 5001001,
                    'slug' => 'senior-backend-engineer',
                    'title' => 'Senior Backend Engineer',
                    'description' => '<p>Build things.</p>',
                    'requirements' => '<p>PHP, Laravel.</p>',
                    'location' => 'Berlin, Germany',
                    'city' => 'Berlin',
                    'country' => 'Germany',
                    'country_code' => 'DE',
                    'remote' => false,
                    'department' => 'Engineering',
                    'employment_type_code' => 'permanent',
                    'careers_url' => 'https://acme.recruitee.com/o/senior-backend-engineer',
                    'careers_apply_url' => 'https://acme.recruitee.com/o/senior-backend-engineer/apply',
                    'created_at' => '2026-05-01T08:00:00Z',
                    'published_at' => '2026-05-02T09:00:00Z',
                    'min_hours' => 32,
                    'max_hours' => 40,
                ],
                [
                    'id' => 5001002,
                    'slug' => 'product-designer',
                    'title' => 'Product Designer',
                    'description' => '<p>Design things.</p>',
                    'requirements' => '<p>Figma.</p>',
                    'location' => '',
                    'city' => 'Amsterdam',
                    'country' => 'Netherlands',
                    'country_code' => 'NL',
                    'remote' => false,
                    'department' => 'Design',
                    'employment_type_code' => 'permanent',
                    'careers_url' => 'https://acme.recruitee.com/o/product-designer',
                    'careers_apply_url' => 'https://acme.recruitee.com/o/product-designer/apply',
                    'created_at' => '2026-05-03T10:00:00Z',
                    'published_at' => '2026-05-04T11:00:00Z',
                    'min_hours' => 40,
                    'max_hours' => 40,
                ],
                [
                    'id' => 5001003,
                    'slug' => 'remote-devops-engineer',
                    'title' => 'Remote DevOps Engineer',
                    'description' => '<p>Operate things.</p>',
                    'requirements' => '<p>Kubernetes.</p>',
                    'location' => 'Remote',
                    'city' => '',
                    'country' => '',
                    'country_code' => '',
                    'remote' => true,
                    'department' => 'Platform',
                    'employment_type_code' => 'contract',
                    'careers_url' => 'https://acme.recruitee.com/o/remote-devops-engineer',
                    'careers_apply_url' => 'https://acme.recruitee.com/o/remote-devops-engineer/apply',
                    'created_at' => '2026-05-05T12:00:00Z',
                    'published_at' => '2026-05-06T13:00:00Z',
                    'min_hours' => 40,
                    'max_hours' => 40,
                ],
            ],
        ];
    }

    #[Test]
    public function happy_path_inserts_roles_and_emits_new_events(): void
    {
        Saloon::fake([
            OffersRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(0, $result->closedCount);

        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(3, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        $remote = Role::query()->where('external_id', '5001003')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);

        $onsite = Role::query()->where('external_id', '5001001')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::UNSPECIFIED, $onsite->workplace_type);
        $this->assertSame('Berlin, Germany', $onsite->location);

        $fallbackLocation = Role::query()->where('external_id', '5001002')->firstOrFail();
        $this->assertSame('Amsterdam, Netherlands', $fallbackLocation->location);

        $fresh = $this->company->fresh();
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            OffersRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);
        $this->assertSame(3, Event::query()->count());

        Saloon::fake([
            OffersRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $second = $this->scraper->run($this->company);

        $this->assertSame(0, $second->newCount);
        $this->assertSame(0, $second->closedCount);
        $this->assertSame(3, $second->fetched);
        $this->assertSame(3, Event::query()->count(), 'Re-running must not duplicate events.');
    }

    #[Test]
    public function http_error_status_returns_result_with_errors(): void
    {
        Saloon::fake([
            OffersRequest::class => MockResponse::make(body: '', status: 503),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function exception_during_fetch_returns_result_with_errors(): void
    {
        Saloon::fake([
            OffersRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('boom');
            },
        ]);

        $originalLastChecked = $this->company->last_checked_at;

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);

        $fresh = $this->company->fresh();
        $this->assertEquals($originalLastChecked, $fresh->last_checked_at);
        $this->assertSame(0, Role::query()->count());
    }
}
