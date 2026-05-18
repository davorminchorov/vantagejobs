<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Personio\PersonioScraper;
use App\Scrapers\Personio\Requests\JobsXmlRequest;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class PersonioScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private PersonioScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::PERSONIO->value,
            'ats_slug' => 'acme',
        ]);

        $this->scraper = new PersonioScraper(new RoleDiffer());
    }

    private function xmlFixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workzag-jobs>
    <position>
        <id>12345</id>
        <subcompany>Acme GmbH</subcompany>
        <office>Berlin</office>
        <department>Engineering</department>
        <recruitingCategory>Engineering</recruitingCategory>
        <name>Senior Backend Engineer</name>
        <employmentType>permanent</employmentType>
        <seniority>senior</seniority>
        <schedule>full-time</schedule>
        <yearsOfExperience>3-5</yearsOfExperience>
        <occupation>Software Engineer</occupation>
        <occupationCategory>IT</occupationCategory>
        <createdAt>2026-05-01T10:00:00+02:00</createdAt>
    </position>
    <position>
        <id>12346</id>
        <subcompany>Acme GmbH</subcompany>
        <office>Remote - EU</office>
        <department>Product</department>
        <recruitingCategory>Product</recruitingCategory>
        <name>Product Manager</name>
        <employmentType>permanent</employmentType>
        <seniority>mid</seniority>
        <schedule>full-time</schedule>
        <createdAt>2026-05-02T10:00:00+02:00</createdAt>
    </position>
    <position>
        <id>12347</id>
        <office>Munich</office>
        <recruitingCategory>Design</recruitingCategory>
        <name>UX Designer</name>
        <employmentType>permanent</employmentType>
        <createdAt>2026-05-03T10:00:00+02:00</createdAt>
    </position>
</workzag-jobs>
XML;
    }

    #[Test]
    public function happy_path_inserts_roles_and_emits_new_events(): void
    {
        Saloon::fake([
            JobsXmlRequest::class => MockResponse::make(body: $this->xmlFixture(), status: 200),
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
    public function remote_office_sets_workplace_type_and_is_remote(): void
    {
        Saloon::fake([
            JobsXmlRequest::class => MockResponse::make(body: $this->xmlFixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $remote = Role::query()->where('external_id', '12346')->firstOrFail();
        $this->assertTrue((bool) $remote->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $remote->workplace_type);

        $onsite = Role::query()->where('external_id', '12345')->firstOrFail();
        $this->assertFalse((bool) $onsite->is_remote);
        $this->assertSame(WorkplaceType::UNSPECIFIED, $onsite->workplace_type);
    }

    #[Test]
    public function falls_back_to_recruiting_category_when_department_missing(): void
    {
        Saloon::fake([
            JobsXmlRequest::class => MockResponse::make(body: $this->xmlFixture(), status: 200),
        ]);

        $this->scraper->run($this->company);

        $role = Role::query()->where('external_id', '12347')->firstOrFail();
        $this->assertSame('Design', $role->department);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsXmlRequest::class => MockResponse::make(body: $this->xmlFixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobsXmlRequest::class => MockResponse::make(body: $this->xmlFixture(), status: 200),
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
            JobsXmlRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('boom');
            },
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }
}
