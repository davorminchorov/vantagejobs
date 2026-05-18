<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Http\Controllers\Companies\CompanyListFilters;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UnlockedFiltersTest extends TestCase
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
    public function stage_filter_narrows_to_matching_funding_stage_values(): void
    {
        $seriesA = Company::factory()->create([
            'name' => 'Alpha Series A',
            'funding_stage' => 'Series A',
        ]);
        Company::factory()->create([
            'name' => 'Bravo Seed',
            'funding_stage' => 'Seed',
        ]);
        Company::factory()->create([
            'name' => 'Charlie Unknown',
            'funding_stage' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/companies?stage[]=Series%20A')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Index')
                ->has('companies.data', 1)
                ->where('companies.data.0.id', $seriesA->id)
                ->where('filters.stage', ['Series A']));
    }

    #[Test]
    public function recently_funded_filter_narrows_to_companies_funded_within_eighteen_months(): void
    {
        $now = CarbonImmutable::now();

        $recent = Company::factory()->create([
            'name' => 'RecentCo',
            'funding_last_raised_at' => $now->subMonths(6),
        ]);
        Company::factory()->create([
            'name' => 'OldCo',
            'funding_last_raised_at' => $now->subYears(3),
        ]);
        Company::factory()->create([
            'name' => 'NeverCo',
            'funding_last_raised_at' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/companies?recently_funded=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.id', $recent->id)
                ->where('filters.recently_funded', true));
    }

    #[Test]
    public function recently_funded_round_trips_through_filter_array_shape(): void
    {
        $filters = CompanyListFilters::fromArray([
            'recently_funded' => '1',
            'tier' => ['A'],
        ]);

        $array = $filters->toArray();

        $this->assertSame(true, $array['recently_funded']);
        $this->assertSame(['A'], $array['tier']);

        // null round-trips as null
        $cleared = CompanyListFilters::fromArray([])->toArray();
        $this->assertNull($cleared['recently_funded']);
    }

    #[Test]
    public function graveyard_exclusion_still_applies_alongside_new_filters(): void
    {
        $now = CarbonImmutable::now();

        // Active + recently funded — should appear.
        $alive = Company::factory()->create([
            'name' => 'AliveCo',
            'is_active' => true,
            'funding_last_raised_at' => $now->subMonths(3),
        ]);
        // Inactive (graveyard) + recently funded — should NOT appear in
        // default view because graveyard rows are excluded.
        Company::factory()->create([
            'name' => 'DeadCo',
            'is_active' => false,
            'funding_last_raised_at' => $now->subMonths(3),
        ]);

        $this->actingAs($this->user)
            ->get('/companies?recently_funded=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.id', $alive->id));
    }

    #[Test]
    public function index_exposes_distinct_funding_stages_as_options(): void
    {
        Company::factory()->create(['funding_stage' => 'Series B']);
        Company::factory()->create(['funding_stage' => 'Series A']);
        Company::factory()->create(['funding_stage' => 'Series A']);
        Company::factory()->create(['funding_stage' => null]);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('options.stages', ['Series A', 'Series B']));
    }
}
