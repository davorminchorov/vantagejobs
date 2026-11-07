<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HiringInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function shows_momentum_per_company_and_totals_across_all_tiers(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
        [$growing, $shrinking] = $this->momentumFixture();

        $props = $this->actingAs($this->user)->get('/insights/hiring')->assertOk()->viewData('page')['props'];

        $this->assertSame(['tier' => 'all'], $props['filters']);
        $this->assertSame([
            'open_now' => 6,
            'change_7d' => -7,
            'change_30d' => 3,
            'new_7d' => 1,
            'closed_7d' => 1,
            'companies_hiring' => 2,
            'companies_total' => 2,
        ], $props['summary']);

        $rows = collect($props['companies'])->keyBy('id');
        $this->assertSame(5, $rows[$growing->id]['open_now']);
        $this->assertSame(2, $rows[$growing->id]['change_7d']);
        $this->assertSame(3, $rows[$growing->id]['change_30d']);
        $this->assertSame(1, $rows[$growing->id]['new_7d']);
        $this->assertSame(1, $rows[$growing->id]['closed_7d']);
        $this->assertCount(90, $rows[$growing->id]['trend']);
        $this->assertSame(-9, $rows[$shrinking->id]['change_7d']);
        $this->assertNull($rows[$shrinking->id]['change_30d']);

        $series = collect($props['series'])->pluck('open_role_count', 'date');
        $this->assertSame(2, $series['2026-10-08']);
        $this->assertSame(13, $series['2026-10-31']);
        $this->assertSame(6, $series['2026-11-07']);
        $this->assertFalse($series->has('2026-10-07'));
    }

    #[Test]
    public function tier_filter_narrows_companies_and_totals(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
        [$growing] = $this->momentumFixture();

        $props = $this->actingAs($this->user)->get('/insights/hiring?tier=a')->assertOk()->viewData('page')['props'];

        $this->assertSame(['tier' => 'A'], $props['filters']);
        $this->assertSame([$growing->id], array_column($props['companies'], 'id'));
        $this->assertSame(5, $props['summary']['open_now']);

        $unknown = $this->actingAs($this->user)->get('/insights/hiring?tier=Z')->viewData('page')['props'];
        $this->assertSame(['tier' => 'all'], $unknown['filters']);
    }

    /**
     * Tier A company growing from 2 (30 days ago) and 3 (7 days ago) to 5 open
     * roles, tier B company shrinking from 10 (7 days ago) to 1, plus an
     * inactive company that must be ignored.
     *
     * @return array{Company, Company}
     */
    private function momentumFixture(): array
    {
        $growing = Company::factory()->create(['tier' => 'A', 'is_active' => true]);
        $shrinking = Company::factory()->create(['tier' => 'B', 'is_active' => true]);
        $inactive = Company::factory()->create(['tier' => 'A', 'is_active' => false]);

        CompanySnapshot::factory()->create(['company_id' => $growing->id, 'snapshot_date' => '2026-10-08', 'open_role_count' => 2]);
        CompanySnapshot::factory()->create(['company_id' => $growing->id, 'snapshot_date' => '2026-10-31', 'open_role_count' => 3]);
        CompanySnapshot::factory()->create(['company_id' => $shrinking->id, 'snapshot_date' => '2026-10-31', 'open_role_count' => 10]);
        CompanySnapshot::factory()->create(['company_id' => $inactive->id, 'snapshot_date' => '2026-10-31', 'open_role_count' => 50]);

        Role::factory()->count(4)->create(['company_id' => $growing->id, 'created_at' => '2026-10-18 10:00:00']);
        Role::factory()->create(['company_id' => $growing->id, 'created_at' => '2026-11-05 10:00:00']);
        $closed = Role::factory()->create(['company_id' => $growing->id, 'is_open' => false, 'created_at' => '2026-10-18 10:00:00']);
        Event::query()->create([
            'company_id' => $growing->id,
            'role_id' => $closed->id,
            'type' => EventType::ROLE_CLOSED->value,
            'title' => 'Role closed',
            'occurred_at' => '2026-11-04 09:00:00',
        ]);
        Role::factory()->create(['company_id' => $shrinking->id, 'created_at' => '2026-09-01 10:00:00']);
        Role::factory()->count(3)->create(['company_id' => $inactive->id, 'created_at' => '2026-11-06 10:00:00']);

        return [$growing, $shrinking];
    }

    #[Test]
    public function company_show_includes_snapshot_series(): void
    {
        $company = Company::factory()->create(['tier' => 'A', 'is_active' => true]);
        $today = CarbonImmutable::now('UTC')->toDateString();
        CompanySnapshot::factory()->create(['company_id' => $company->id, 'snapshot_date' => $today, 'open_role_count' => 3]);

        $response = $this->actingAs($this->user)->get("/companies/{$company->id}");
        $response->assertOk();

        $trend = $response->viewData('page')['props']['hiring_trend'];
        $this->assertCount(1, $trend);
        $this->assertSame(3, $trend[0]['open_role_count']);
    }
}
