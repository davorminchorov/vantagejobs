<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\CompanySnapshot;
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

    #[Test]
    public function default_sort_puts_the_company_with_the_newest_open_posting_first(): void
    {
        $now = CarbonImmutable::now();

        $older = Company::factory()->create(['tier' => 'A', 'name' => 'Alpha']);
        $newest = Company::factory()->create(['tier' => 'C', 'name' => 'Zeta']);
        $closedOnly = Company::factory()->create(['tier' => 'A', 'name' => 'Aaron']);

        Role::factory()->create(['company_id' => $older->id, 'is_open' => true, 'posted_at' => $now->subDays(10)]);
        Role::factory()->create(['company_id' => $newest->id, 'is_open' => true, 'posted_at' => $now->subDay()]);
        // A closed role, however recent, must not lift the company to the top.
        Role::factory()->create(['company_id' => $closedOnly->id, 'is_open' => false, 'posted_at' => $now]);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'newest_posting')
                ->where('options.sorts', ['newest_posting', 'tier', 'name', 'new_this_week', 'quality'])
                ->where('companies.data.0.name', 'Zeta')
                ->where('companies.data.1.name', 'Alpha')
                ->where('companies.data.2.name', 'Aaron')
                ->where('companies.data.2.latest_open_posted_at', null)
                ->whereType('companies.data.0.latest_open_posted_at', 'string'));
    }

    #[Test]
    public function companies_without_dated_open_roles_sort_last_by_tier_then_name(): void
    {
        $dated = Company::factory()->create(['tier' => 'C', 'name' => 'Dated']);
        $undated = Company::factory()->create(['tier' => 'A', 'name' => 'Undated']);
        Company::factory()->create(['tier' => 'A', 'name' => 'Empty']);

        Role::factory()->create(['company_id' => $dated->id, 'is_open' => true, 'posted_at' => CarbonImmutable::now()->subDays(20)]);
        Role::factory()->create(['company_id' => $undated->id, 'is_open' => true, 'posted_at' => null]);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies.data.0.name', 'Dated')
                ->where('companies.data.1.name', 'Empty')
                ->where('companies.data.2.name', 'Undated'));
    }

    #[Test]
    public function sort_by_tier_orders_by_tier_then_name(): void
    {
        $recent = Company::factory()->create(['tier' => 'C', 'name' => 'Recent']);
        Company::factory()->create(['tier' => 'A', 'name' => 'Bravo']);
        Company::factory()->create(['tier' => 'A', 'name' => 'Alpha']);
        Role::factory()->create(['company_id' => $recent->id, 'is_open' => true, 'posted_at' => CarbonImmutable::now()]);

        $this->actingAs($this->user)
            ->get('/companies?sort=tier')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'tier')
                ->where('companies.data.0.name', 'Alpha')
                ->where('companies.data.1.name', 'Bravo')
                ->where('companies.data.2.name', 'Recent'));
    }

    #[Test]
    public function sort_by_name_ignores_tier(): void
    {
        Company::factory()->create(['tier' => 'C', 'name' => 'Alpha']);
        Company::factory()->create(['tier' => 'A', 'name' => 'Bravo']);

        $this->actingAs($this->user)
            ->get('/companies?sort=name')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'name')
                ->where('companies.data.0.name', 'Alpha')
                ->where('companies.data.1.name', 'Bravo'));
    }

    #[Test]
    public function unknown_sort_falls_back_to_newest_posting(): void
    {
        Company::factory()->create();

        $this->actingAs($this->user)
            ->get('/companies?sort=drop_table')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.sort', 'newest_posting'));
    }

    #[Test]
    public function stale_filter_older_than_7d_includes_old_and_never_checked_companies(): void
    {
        $now = CarbonImmutable::now();
        $fresh = Company::factory()->create(['name' => 'Fresh', 'last_checked_at' => $now->subDays(2)]);
        $old = Company::factory()->create(['name' => 'Old', 'last_checked_at' => $now->subDays(10)]);
        $never = Company::factory()->create(['name' => 'Never', 'last_checked_at' => null]);

        $this->actingAs($this->user)
            ->get('/companies?last_checked=older_than_7d&sort=name')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.last_checked', 'older_than_7d')
                ->has('companies.data', 2)
                ->where('companies.data.0.id', $never->id)
                ->where('companies.data.1.id', $old->id));

        $this->assertNotNull($fresh);
    }

    #[Test]
    public function older_than_7d_is_a_listed_last_checked_option(): void
    {
        $this->actingAs($this->user)
            ->get('/companies')
            ->assertInertia(fn ($page) => $page->where('options.last_checked', fn ($v) => in_array('older_than_7d', $v->all(), true)));
    }

    #[Test]
    public function new_this_week_filter_keeps_companies_with_open_roles_created_in_last_seven_days(): void
    {
        $fresh = Company::factory()->create(['name' => 'Fresh']);
        $stale = Company::factory()->create(['name' => 'Stale']);
        $closed = Company::factory()->create(['name' => 'Closed']);

        Role::factory()->create(['company_id' => $fresh->id, 'is_open' => true, 'created_at' => CarbonImmutable::now()->subDays(2)]);
        Role::factory()->create(['company_id' => $stale->id, 'is_open' => true, 'created_at' => CarbonImmutable::now()->subDays(20)]);
        Role::factory()->create(['company_id' => $closed->id, 'is_open' => false, 'created_at' => CarbonImmutable::now()->subDay()]);

        $this->actingAs($this->user)
            ->get('/companies?new_this_week=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.new_this_week', true)
                ->has('companies.data', 1)
                ->where('companies.data.0.id', $fresh->id)
                ->where('companies.data.0.new_roles_7d', 1));
    }

    #[Test]
    public function unscraped_filter_matches_missing_ats_type_slug_or_failed_redetection(): void
    {
        $noType = Company::factory()->create(['name' => 'NoType', 'ats_type' => null]);
        $noSlug = Company::factory()->create(['name' => 'NoSlug', 'ats_slug' => null]);
        $failed = Company::factory()->create(['name' => 'Failed', 'ats_status' => 'redetect_failed']);
        Company::factory()->create(['name' => 'Healthy', 'ats_type' => 'greenhouse', 'ats_slug' => 'healthy']);

        $this->actingAs($this->user)
            ->get('/companies?unscraped=1&sort=name')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.unscraped', true)
                ->has('companies.data', 3)
                ->where('companies.data.0.id', $failed->id)
                ->where('companies.data.1.id', $noSlug->id)
                ->where('companies.data.2.id', $noType->id));
    }

    #[Test]
    public function new_filters_default_to_false_and_ignore_garbage(): void
    {
        $this->actingAs($this->user)
            ->get('/companies?new_this_week=banana&unscraped[]=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.new_this_week', false)
                ->where('filters.unscraped', false));
    }

    #[Test]
    public function rows_expose_new_roles_quality_score_signals_and_funding(): void
    {
        $now = CarbonImmutable::now();
        $company = Company::factory()->create([
            'funding_last_raised_at' => $now->subMonths(2),
            'last_checked_at' => $now->subHour(),
        ]);
        $bare = Company::factory()->create(['name' => 'Zed']);

        Role::factory()->count(2)->create(['company_id' => $company->id, 'is_open' => true, 'created_at' => $now->subDay()]);
        Role::factory()->create(['company_id' => $company->id, 'is_open' => true, 'created_at' => $now->subDays(30)]);

        CompanySnapshot::factory()->create([
            'company_id' => $company->id,
            'snapshot_date' => $now->subDays(5)->toDateString(),
            'quality_score' => 40,
            'has_recent_layoff_event' => false,
        ]);
        CompanySnapshot::factory()->create([
            'company_id' => $company->id,
            'snapshot_date' => $now->toDateString(),
            'quality_score' => 77,
            'has_recent_layoff_event' => true,
        ]);

        $this->actingAs($this->user)
            ->get('/companies?sort=name')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies.data.0.id', $company->id)
                ->where('companies.data.0.new_roles_7d', 2)
                ->where('companies.data.0.open_roles_count', 3)
                ->where('companies.data.0.quality_score', 77)
                ->where('companies.data.0.has_recent_layoff_event', true)
                ->has('companies.data.0.funding_last_raised_at')
                ->has('companies.data.0.last_checked_at')
                ->has('companies.data.0.ats_type')
                ->has('companies.data.0.ats_slug')
                ->has('companies.data.0.ats_status')
                ->where('companies.data.1.id', $bare->id)
                ->where('companies.data.1.quality_score', null)
                ->where('companies.data.1.new_roles_7d', 0));
    }

    #[Test]
    public function hiring_trend_is_ninety_daily_values_forward_filled_and_null_before_first_snapshot(): void
    {
        $today = CarbonImmutable::today();
        $company = Company::factory()->create(['name' => 'Trendy']);
        $empty = Company::factory()->create(['name' => 'Zzz']);

        CompanySnapshot::factory()->create(['company_id' => $company->id, 'snapshot_date' => $today->subDays(10)->toDateString(), 'open_role_count' => 5]);
        CompanySnapshot::factory()->create(['company_id' => $company->id, 'snapshot_date' => $today->subDays(3)->toDateString(), 'open_role_count' => 9]);
        CompanySnapshot::factory()->create(['company_id' => $company->id, 'snapshot_date' => $today->subDays(200)->toDateString(), 'open_role_count' => 99]);

        $this->actingAs($this->user)
            ->get('/companies?sort=name')
            ->assertOk()
            ->assertInertia(function ($page) use ($company, $empty): void {
                $page->where('companies.data.0.id', $company->id);
                $trend = $page->toArray()['props']['companies']['data'][0]['hiring_trend'];
                $emptyTrend = $page->toArray()['props']['companies']['data'][1]['hiring_trend'];

                $this->assertCount(90, $trend);
                $this->assertNull($trend[0]);
                $this->assertNull($trend[78]);
                $this->assertSame(5, $trend[79]);
                $this->assertSame(5, $trend[85]);
                $this->assertSame(9, $trend[86]);
                $this->assertSame(9, $trend[89]);
                $this->assertSame(array_fill(0, 90, null), $emptyTrend);
                $this->assertNotNull($empty);
            });
    }

    #[Test]
    public function sort_by_quality_puts_highest_score_first_and_unscored_last(): void
    {
        $low = Company::factory()->create(['name' => 'Low']);
        $high = Company::factory()->create(['name' => 'High']);
        $none = Company::factory()->create(['name' => 'None']);

        CompanySnapshot::factory()->create(['company_id' => $low->id, 'quality_score' => 20]);
        CompanySnapshot::factory()->create(['company_id' => $high->id, 'quality_score' => 90]);

        $this->actingAs($this->user)
            ->get('/companies?sort=quality')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'quality')
                ->where('companies.data.0.id', $high->id)
                ->where('companies.data.1.id', $low->id)
                ->where('companies.data.2.id', $none->id));
    }

    #[Test]
    public function sort_by_new_this_week_orders_by_new_role_count(): void
    {
        $one = Company::factory()->create(['name' => 'One']);
        $three = Company::factory()->create(['name' => 'Three']);
        $zero = Company::factory()->create(['name' => 'Zero']);

        Role::factory()->count(1)->create(['company_id' => $one->id, 'is_open' => true]);
        Role::factory()->count(3)->create(['company_id' => $three->id, 'is_open' => true]);

        $this->actingAs($this->user)
            ->get('/companies?sort=new_this_week')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies.data.0.id', $three->id)
                ->where('companies.data.1.id', $one->id)
                ->where('companies.data.2.id', $zero->id));
    }
}
