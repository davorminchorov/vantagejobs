<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Models\Company;
use App\Models\CompanySnapshot;
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
    public function shows_global_aggregated_series_for_a_tier_only(): void
    {
        $a1 = Company::factory()->create(['tier' => 'A', 'is_active' => true]);
        $a2 = Company::factory()->create(['tier' => 'A', 'is_active' => true]);
        $b = Company::factory()->create(['tier' => 'B', 'is_active' => true]);
        $gy = Company::factory()->create(['tier' => 'A', 'is_active' => false]);

        $today = CarbonImmutable::now('UTC')->toDateString();
        CompanySnapshot::factory()->create(['company_id' => $a1->id, 'snapshot_date' => $today, 'open_role_count' => 4]);
        CompanySnapshot::factory()->create(['company_id' => $a2->id, 'snapshot_date' => $today, 'open_role_count' => 7]);
        CompanySnapshot::factory()->create(['company_id' => $b->id, 'snapshot_date' => $today, 'open_role_count' => 99]);
        CompanySnapshot::factory()->create(['company_id' => $gy->id, 'snapshot_date' => $today, 'open_role_count' => 99]);

        $response = $this->actingAs($this->user)->get('/insights/hiring');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $global = $props['global'];
        $this->assertNotEmpty($global);
        $row = collect($global)->firstWhere('date', $today);
        $this->assertNotNull($row);
        $this->assertSame(11, $row['open_role_count']);
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
