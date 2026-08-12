<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IndexCompaniesTest extends TestCase
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
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/companies')->assertRedirect('/login');
    }

    #[Test]
    public function index_renders_paginated_company_rows_with_open_role_counts(): void
    {
        // Names chosen so alphabetical sort within tier puts the hiring co first.
        $hiring = Company::factory()->create(['tier' => 'A', 'name' => 'Aardvark Inc']);
        Company::factory()->create(['tier' => 'A', 'name' => 'Beta Co']);
        Company::factory()->create(['tier' => 'A', 'name' => 'Gamma Co']);

        Role::factory()->count(2)->create(['company_id' => $hiring->id, 'is_open' => true]);
        Role::factory()->create(['company_id' => $hiring->id, 'is_open' => false]);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Index')
                ->has('companies.data', 3)
                ->where('companies.total', 3)
                ->where('companies.data.0.name', 'Aardvark Inc')
                ->where('companies.data.0.open_roles_count', 2)
            );
    }

    #[Test]
    public function filters_by_tier(): void
    {
        Company::factory()->create(['tier' => 'A', 'name' => 'Atier']);
        Company::factory()->create(['tier' => 'B', 'name' => 'Btier']);
        Company::factory()->create(['tier' => 'C', 'name' => 'Ctier']);

        $this->actingAs($this->user)
            ->get('/companies?tier=A')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('companies.data.0.name', 'Atier')
                ->where('filters.tier', ['A'])
            );
    }

    #[Test]
    public function filters_by_status(): void
    {
        Company::factory()->create(['status' => 'researching', 'name' => 'R Co']);
        Company::factory()->create(['status' => 'applied', 'name' => 'A Co']);

        $this->actingAs($this->user)
            ->get('/companies?status=applied')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('companies.data.0.name', 'A Co')
            );
    }

    #[Test]
    public function index_exposes_ats_status_per_row(): void
    {
        Company::factory()->create(['name' => 'Flagged Co', 'ats_status' => 'redetect_failed']);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertInertia(fn ($page) => $page->where('companies.data.0.ats_status', 'redetect_failed'));
    }

    #[Test]
    public function filters_by_ats_type(): void
    {
        Company::factory()->create(['ats_type' => 'greenhouse', 'name' => 'GH Co']);
        Company::factory()->create(['ats_type' => 'lever', 'name' => 'Lever Co']);

        $this->actingAs($this->user)
            ->get('/companies?ats_type=lever')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('companies.data.0.name', 'Lever Co')
            );
    }

    #[Test]
    public function filters_by_last_checked_range(): void
    {
        Company::factory()->create(['name' => 'Never', 'last_checked_at' => null]);
        Company::factory()->create(['name' => 'Fresh', 'last_checked_at' => CarbonImmutable::now()->subHours(2)]);
        Company::factory()->create(['name' => 'Old', 'last_checked_at' => CarbonImmutable::now()->subDays(60)]);

        $this->actingAs($this->user)
            ->get('/companies?last_checked=never')
            ->assertInertia(fn ($page) => $page->where('companies.data.0.name', 'Never')->where('companies.total', 1));

        $this->actingAs($this->user)
            ->get('/companies?last_checked=last_24h')
            ->assertInertia(fn ($page) => $page->where('companies.data.0.name', 'Fresh')->where('companies.total', 1));

        $this->actingAs($this->user)
            ->get('/companies?last_checked=older_than_30d')
            ->assertInertia(fn ($page) => $page->where('companies.data.0.name', 'Old')->where('companies.total', 1));
    }

    #[Test]
    public function filters_by_has_open_roles_true_and_false(): void
    {
        $withOpen = Company::factory()->create(['name' => 'Hiring Co']);
        Role::factory()->create(['company_id' => $withOpen->id, 'is_open' => true]);

        Company::factory()->create(['name' => 'Quiet Co']);

        $this->actingAs($this->user)
            ->get('/companies?has_open_roles=1')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('companies.data.0.name', 'Hiring Co'));

        $this->actingAs($this->user)
            ->get('/companies?has_open_roles=0')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 1)
                ->where('companies.data.0.name', 'Quiet Co'));
    }

    #[Test]
    public function search_query_matches_name_domain_and_slug_case_insensitively(): void
    {
        Company::factory()->create(['name' => 'Acme Robotics', 'domain' => 'acme.test', 'ats_slug' => 'acme']);
        Company::factory()->create(['name' => 'Globex', 'domain' => 'globex.test', 'ats_slug' => 'globex']);

        $this->actingAs($this->user)
            ->get('/companies?q=ROBOT')
            ->assertInertia(fn ($page) => $page->where('companies.total', 1)->where('companies.data.0.name', 'Acme Robotics'));

        $this->actingAs($this->user)
            ->get('/companies?q=globex.test')
            ->assertInertia(fn ($page) => $page->where('companies.total', 1)->where('companies.data.0.name', 'Globex'));
    }

    #[Test]
    public function tenant_isolation_hides_other_tenants_companies(): void
    {
        Company::factory()->create(['name' => 'Mine']);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            Company::factory()->create(['name' => 'Theirs']);
        });

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertInertia(fn ($page) => $page->where('companies.total', 1)->where('companies.data.0.name', 'Mine'));
    }

    #[Test]
    public function pagination_caps_at_twenty_per_page(): void
    {
        Company::factory()->count(25)->create(['tier' => 'C']);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 25)
                ->where('companies.per_page', 20)
                ->has('companies.data', 20));
    }

    #[Test]
    public function filters_accept_multi_value_tier_arrays(): void
    {
        Company::factory()->create(['tier' => 'A', 'name' => 'A Co']);
        Company::factory()->create(['tier' => 'B', 'name' => 'B Co']);
        Company::factory()->create(['tier' => 'C', 'name' => 'C Co']);

        $this->actingAs($this->user)
            ->get('/companies?tier[]=A&tier[]=B')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 2)
                ->where('filters.tier', ['A', 'B']));
    }

    #[Test]
    public function filters_accept_multi_value_tier_csv(): void
    {
        Company::factory()->create(['tier' => 'A', 'name' => 'A Co']);
        Company::factory()->create(['tier' => 'B', 'name' => 'B Co']);
        Company::factory()->create(['tier' => 'C', 'name' => 'C Co']);

        $this->actingAs($this->user)
            ->get('/companies?tier=A,B')
            ->assertInertia(fn ($page) => $page
                ->where('companies.total', 2)
                ->where('filters.tier', ['A', 'B']));
    }

    #[Test]
    public function unknown_filter_keys_and_values_are_dropped_silently(): void
    {
        Company::factory()->create(['tier' => 'A']);

        // `tier=Q` is outside the whitelist; `bogus_field=lol` is an unknown key.
        // Either page-level controller should ignore both rather than 500.
        $this->actingAs($this->user)
            ->get('/companies?tier=Q&bogus_field=lol&last_checked=tomorrow')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tier', [])
                ->where('filters.last_checked', null));
    }

    #[Test]
    public function unknown_filter_values_are_ignored_gracefully(): void
    {
        Company::factory()->create();

        $this->actingAs($this->user)
            ->get('/companies?tier=&status=&ats_type=&has_open_roles=&last_checked=&q=')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tier', [])
                ->where('filters.status', [])
                ->where('filters.ats_type', [])
                ->where('filters.has_open_roles', null)
                ->where('filters.last_checked', null)
                ->where('filters.q', null));
    }
}
