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
use App\Scrapers\Workable\Requests\WidgetAccountRequest;
use App\Scrapers\Workable\WorkableScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class WorkableScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private WorkableScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::WORKABLE->value,
            'ats_slug' => 'acme',
        ]);

        $this->scraper = new WorkableScraper(new RoleDiffer());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return [
            'jobs' => [
                [
                    'id' => 'JOB001',
                    'shortcode' => 'A1B2C3',
                    'title' => 'Senior Backend Engineer',
                    'url' => 'https://apply.workable.com/acme/j/A1B2C3/',
                    'application_url' => 'https://apply.workable.com/acme/j/A1B2C3/apply/',
                    'state' => 'published',
                    'department' => 'Engineering',
                    'created_at' => '2026-05-01T10:00:00Z',
                    'description' => '<p>Build things.</p>',
                    'location' => [
                        'city' => 'Berlin',
                        'region' => 'Berlin',
                        'country' => 'Germany',
                        'country_code' => 'DE',
                        'telecommuting' => false,
                        'workplace' => 'on_site',
                    ],
                ],
                [
                    'id' => 'JOB002',
                    'shortcode' => 'D4E5F6',
                    'title' => 'Remote Product Designer',
                    'url' => 'https://apply.workable.com/acme/j/D4E5F6/',
                    'state' => 'published',
                    'department' => 'Design',
                    'created_at' => '2026-05-02T10:00:00Z',
                    'description' => '<p>Design things.</p>',
                    'location' => [
                        'city' => '',
                        'region' => '',
                        'country' => 'United States',
                        'country_code' => 'US',
                        'telecommuting' => true,
                        'workplace' => 'remote',
                    ],
                ],
                [
                    'id' => 'JOB003',
                    'shortcode' => 'G7H8I9',
                    'title' => 'Hybrid Marketing Lead',
                    'url' => 'https://apply.workable.com/acme/j/G7H8I9/',
                    'state' => 'published',
                    'department' => 'Marketing',
                    'created_at' => '2026-05-03T10:00:00Z',
                    'description' => '<p>Market things.</p>',
                    'location' => [
                        'city' => 'London',
                        'region' => '',
                        'country' => 'United Kingdom',
                        'country_code' => 'GB',
                        'telecommuting' => false,
                        'workplace' => 'hybrid',
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function happy_path_inserts_roles_and_emits_new_events(): void
    {
        Saloon::fake([
            WidgetAccountRequest::class => MockResponse::make(
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
    public function workplace_and_remote_mapping_uses_widget_fields(): void
    {
        Saloon::fake([
            WidgetAccountRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $onsite = Role::query()->where('external_id', 'A1B2C3')->firstOrFail();
        $this->assertSame(WorkplaceType::ON_SITE, $onsite->workplace_type);
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame('Berlin, Berlin, Germany', $onsite->location);

        $remote = Role::query()->where('external_id', 'D4E5F6')->firstOrFail();
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame('United States', $remote->location);

        $hybrid = Role::query()->where('external_id', 'G7H8I9')->firstOrFail();
        $this->assertSame(WorkplaceType::HYBRID, $hybrid->workplace_type);
        $this->assertSame('London, United Kingdom', $hybrid->location);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            WidgetAccountRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            WidgetAccountRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $second = $this->scraper->run($this->company);

        $this->assertSame(0, $second->newCount);
        $this->assertSame(0, $second->closedCount);
        $this->assertSame(3, $second->fetched);
        $this->assertSame(3, Event::query()->count(), 'Re-running must not duplicate events.');
    }

    #[Test]
    public function http_error_returns_result_with_errors(): void
    {
        Saloon::fake([
            WidgetAccountRequest::class => MockResponse::make(body: '', status: 500),
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
            WidgetAccountRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('boom');
            },
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }
}
