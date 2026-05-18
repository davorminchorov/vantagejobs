<?php

declare(strict_types=1);

namespace Tests\Feature\SavedViews;

use App\Http\Controllers\Companies\CompanyListFilters;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApplySavedViewTest extends TestCase
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
    public function filter_payload_round_trips_through_a_saved_view(): void
    {
        Company::factory()->create(['name' => 'A Co', 'tier' => 'A']);
        Company::factory()->create(['name' => 'B Co', 'tier' => 'B']);
        Company::factory()->create(['name' => 'C Co', 'tier' => 'C']);

        // Construct a saved view by hand mirroring the canonical wire shape.
        $view = SavedView::factory()->forEntity('companies')->create([
            'user_id' => $this->user->id,
            'name' => 'A-tier only',
            'filters' => ['tier' => ['A']],
        ]);

        // Apply the filters as if the nav had built the query string and
        // navigated the browser to /companies?tier[]=A.
        $this->actingAs($this->user)
            ->get('/companies?'.http_build_query($view->filters))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('companies.data.0.name', 'A Co')
                ->where('filters.tier', ['A']));
    }

    #[Test]
    public function company_list_filters_round_trip_through_to_array(): void
    {
        $payload = [
            'tier' => ['A', 'B'],
            'status' => ['applied'],
            'ats_type' => ['greenhouse'],
            'has_open_roles' => true,
            'last_checked' => 'last_7d',
            'q' => 'acme',
        ];

        $parsed = CompanyListFilters::fromArray($payload);
        $canonical = $parsed->toArray();

        $this->assertSame(['A', 'B'], $canonical['tier']);
        $this->assertSame(['applied'], $canonical['status']);
        $this->assertSame(['greenhouse'], $canonical['ats_type']);
        $this->assertTrue($canonical['has_open_roles']);
        $this->assertSame('last_7d', $canonical['last_checked']);
        $this->assertSame('acme', $canonical['q']);
    }
}
