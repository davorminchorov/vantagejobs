<?php

declare(strict_types=1);

namespace Tests\Feature\SavedViews;

use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DefensiveDegradationTest extends TestCase
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
    public function saved_view_with_unknown_filter_keys_still_loads_the_page(): void
    {
        Company::factory()->create(['tier' => 'A', 'name' => 'Acme']);

        SavedView::factory()->forEntity('companies')->create([
            'user_id' => $this->user->id,
            'name' => 'Stale view',
            'filters' => [
                'tier' => ['A'],
                'this_filter_no_longer_exists' => 'whatever',
                'quality_score' => ['high'],
                'ghost_flagged' => true,
            ],
        ]);

        $this->actingAs($this->user)
            ->get('/companies?tier[]=A&this_filter_no_longer_exists=whatever&quality_score[]=high&ghost_flagged=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('filters.tier', ['A']));
    }

    #[Test]
    public function saved_view_with_out_of_whitelist_enum_values_drops_them(): void
    {
        Company::factory()->create(['tier' => 'A']);

        $this->actingAs($this->user)
            ->get('/companies?tier[]=A&tier[]=ZZZ&status[]=alien_status')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tier', ['A'])
                ->where('filters.status', []));
    }
}
