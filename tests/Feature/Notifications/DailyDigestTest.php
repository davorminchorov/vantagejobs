<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Role;
use App\Notifications\DailyDigestQuery;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the DailyDigestQuery assembler: the four digest sections, the
 * 24h window, the empty check, and tenant scoping.
 */
final class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    private function query(): DailyDigestQuery
    {
        return app(DailyDigestQuery::class);
    }

    #[Test]
    public function groups_recent_events_by_company(): void
    {
        $now = CarbonImmutable::now();

        $companyA = Company::factory()->create(['name' => 'Acme', 'tier' => 'A']);
        $companyB = Company::factory()->create(['name' => 'Beta', 'tier' => 'B']);

        Event::factory()->count(2)->create([
            'company_id' => $companyA->id,
            'occurred_at' => $now->subHours(2),
        ]);
        Event::factory()->create([
            'company_id' => $companyB->id,
            'occurred_at' => $now->subHours(3),
        ]);

        $digest = $this->query()->build($now);

        $this->assertCount(2, $digest->companyActivity);
        $this->assertSame(3, $digest->eventCount());

        $names = array_map(fn ($activity) => $activity->companyName, $digest->companyActivity);
        $this->assertContains('Acme', $names);
        $this->assertContains('Beta', $names);
    }

    #[Test]
    public function excludes_events_older_than_the_window(): void
    {
        $now = CarbonImmutable::now();
        $company = Company::factory()->create();

        Event::factory()->create([
            'company_id' => $company->id,
            'occurred_at' => $now->subHours(5),
        ]);
        Event::factory()->create([
            'company_id' => $company->id,
            'occurred_at' => $now->subHours(30),
        ]);

        $digest = $this->query()->build($now);

        $this->assertSame(1, $digest->eventCount());
    }

    #[Test]
    public function counts_pending_discover_listings_only(): void
    {
        $now = CarbonImmutable::now();

        // Reuse one source across listings: the source factory's type is unique
        // per (tenant_id, type), so distinct sources would collide here.
        $source = AggregatorSource::factory()->create();

        AggregatorListing::factory()->count(2)->recycle($source)->create(['status' => AggregatorListingStatus::NEW]);
        AggregatorListing::factory()->recycle($source)->create(['status' => AggregatorListingStatus::OVERFLOW]);
        AggregatorListing::factory()->recycle($source)->create(['status' => AggregatorListingStatus::DISMISSED]);
        AggregatorListing::factory()->recycle($source)->create(['status' => AggregatorListingStatus::KEPT]);

        $digest = $this->query()->build($now);

        $this->assertSame(3, $digest->discoverQueueCount);
    }

    #[Test]
    public function surfaces_stale_a_tier_companies(): void
    {
        $now = CarbonImmutable::now();

        $staleNever = Company::factory()->create([
            'name' => 'NeverChecked',
            'tier' => 'A',
            'last_checked_at' => null,
        ]);
        $staleOld = Company::factory()->create([
            'name' => 'Old',
            'tier' => 'A',
            'last_checked_at' => $now->subDays(10),
        ]);

        // Fresh A-tier: checked yesterday, not stale.
        Company::factory()->create([
            'tier' => 'A',
            'last_checked_at' => $now->subDay(),
        ]);
        // Stale, but B-tier: excluded.
        Company::factory()->create([
            'tier' => 'B',
            'last_checked_at' => $now->subDays(30),
        ]);

        $digest = $this->query()->build($now);

        $ids = $digest->staleATierCompanies->pluck('id')->all();
        $this->assertContains($staleNever->id, $ids);
        $this->assertContains($staleOld->id, $ids);
        $this->assertCount(2, $digest->staleATierCompanies);
    }

    #[Test]
    public function counts_due_follow_ups(): void
    {
        $now = CarbonImmutable::now();

        $company = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => 'applied',
        ]);
        Contact::factory()->create([
            'company_id' => $company->id,
            'last_touch' => null,
        ]);

        $digest = $this->query()->build($now);

        $this->assertSame(1, $digest->followUpQueueCount);
    }

    #[Test]
    public function reports_empty_when_nothing_is_pending(): void
    {
        $now = CarbonImmutable::now();

        $digest = $this->query()->build($now);

        $this->assertTrue($digest->isEmpty());
        $this->assertSame(0, $digest->eventCount());
        $this->assertSame(0, $digest->discoverQueueCount);
        $this->assertSame(0, $digest->followUpQueueCount);
        $this->assertTrue($digest->staleATierCompanies->isEmpty());
    }

    #[Test]
    public function is_not_empty_when_only_the_discover_queue_has_items(): void
    {
        $now = CarbonImmutable::now();
        AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);

        $digest = $this->query()->build($now);

        $this->assertFalse($digest->isEmpty());
    }

    #[Test]
    public function does_not_read_across_the_tenant_boundary(): void
    {
        $now = CarbonImmutable::now();

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function () use ($now): void {
            $company = Company::factory()->create(['tier' => 'A', 'last_checked_at' => null]);
            Event::factory()->create([
                'company_id' => $company->id,
                'occurred_at' => $now->subHour(),
            ]);
            AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);
        });

        $digest = $this->query()->build($now);

        $this->assertTrue($digest->isEmpty());
    }
}
