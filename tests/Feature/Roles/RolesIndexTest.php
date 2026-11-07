<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RolesIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function role(Company $company, array $attributes = []): Role
    {
        return Role::factory()->create([
            'company_id' => $company->id,
            'posted_at' => CarbonImmutable::now()->subDays(2),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function page(array $query = []): Assert
    {
        $response = $this->actingAs($this->user)->get(route('roles.index', $query))->assertOk();

        return Assert::fromTestResponse($response);
    }

    #[Test]
    public function groups_by_company_ordered_by_latest_role_activity(): void
    {
        $old = Company::factory()->create(['name' => 'Old Co']);
        $new = Company::factory()->create(['name' => 'New Co']);
        $this->role($old, ['title' => 'Old role', 'posted_at' => CarbonImmutable::now()->subDays(10)]);
        $this->role($new, ['title' => 'Older at new', 'posted_at' => CarbonImmutable::now()->subDays(5)]);
        $this->role($new, ['title' => 'Newest at new', 'posted_at' => CarbonImmutable::now()->subDay()]);

        $this->page()->component('Roles/Index')
            ->has('groups.data', 2)
            ->where('groups.data.0.company.name', 'New Co')
            ->where('groups.data.0.total_matching', 2)
            ->where('groups.data.0.roles.0.title', 'Newest at new')
            ->where('groups.data.0.roles.1.title', 'Older at new')
            ->where('groups.data.1.company.name', 'Old Co');
    }

    #[Test]
    public function ties_on_activity_fall_back_to_company_id(): void
    {
        $at = CarbonImmutable::now()->subDay();
        $first = Company::factory()->create();
        $second = Company::factory()->create();
        $this->role($second, ['posted_at' => $at]);
        $this->role($first, ['posted_at' => $at]);

        $this->page()
            ->where('groups.data.0.company.id', $first->id)
            ->where('groups.data.1.company.id', $second->id);
    }

    #[Test]
    public function undated_roles_use_created_at_for_ordering(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $this->role($a, ['posted_at' => CarbonImmutable::now()->subDays(3)]);
        $this->role($b, ['posted_at' => null]);

        $this->page()->where('groups.data.0.company.id', $b->id);
    }

    #[Test]
    public function caps_roles_at_ten_but_reports_the_full_total_and_ids(): void
    {
        $company = Company::factory()->create();
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $ids[] = $this->role($company, ['posted_at' => CarbonImmutable::now()->subHours($i + 1)])->id;
        }

        $this->page()
            ->has('groups.data', 1)
            ->where('groups.data.0.total_matching', 13)
            ->has('groups.data.0.roles', 10)
            ->has('groups.data.0.role_ids', 13)
            ->where('groups.data.0.role_ids', $ids)
            ->where('groups.data.0.roles.0.id', $ids[0]);
    }

    #[Test]
    public function company_filter_returns_up_to_two_hundred_roles_for_the_group(): void
    {
        $company = Company::factory()->create();
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = Role::factory()->make([
                'company_id' => $company->id,
                'external_id' => "ext-{$i}",
                'posted_at' => CarbonImmutable::now()->subMinutes($i + 1),
            ])->getAttributes() + ['tenant_id' => $company->tenant_id, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach ($rows as $row) {
            $row['raw'] = null;
            \Illuminate\Support\Facades\DB::table('roles')->insert($row);
        }

        $this->page()->has('groups.data.0.roles', 10)->where('groups.data.0.total_matching', 205);

        $this->page(['company' => $company->id])
            ->where('groups.data.0.total_matching', 205)
            ->has('groups.data.0.role_ids', 205)
            ->has('groups.data.0.roles', 200);
    }

    #[Test]
    public function paginates_groups_twelve_per_page(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->role(Company::factory()->create(), ['posted_at' => CarbonImmutable::now()->subHours($i + 1)]);
        }

        $this->page()->has('groups.data', 12);
        $this->page(['page' => 2])->has('groups.data', 2);
    }

    #[Test]
    public function consecutive_pages_do_not_overlap_and_total_counts_companies(): void
    {
        $at = CarbonImmutable::now()->subDay();
        $companies = [];
        for ($i = 0; $i < 30; $i++) {
            $company = Company::factory()->create();
            // Several roles per company, and many identical activity times, so
            // both the grouping and the ordering tie-break are exercised.
            $this->role($company, ['posted_at' => $i % 3 === 0 ? $at : $at->subMinutes($i)]);
            $this->role($company, ['posted_at' => $at->subDays(2)]);
            $companies[] = $company->id;
        }

        $ids = fn (int $page): array => collect(
            $this->actingAs($this->user)
                ->get(route('roles.index', ['page' => $page]))
                ->viewData('page')['props']['groups']['data'],
        )->pluck('company.id')->all();

        $first = $ids(1);
        $second = $ids(2);
        $third = $ids(3);

        $this->assertCount(12, $first);
        $this->assertCount(12, $second);
        $this->assertCount(6, $third);
        $this->assertSame([], array_intersect($first, $second));
        $this->assertSame([], array_intersect($second, $third));
        $this->assertEqualsCanonicalizing($companies, [...$first, ...$second, ...$third]);

        $this->page(['page' => 2])
            ->where('groups.total', 30)
            ->where('groups.current_page', 2)
            ->where('groups.last_page', 3);
    }

    #[Test]
    public function scroll_request_merges_the_groups_data_path_exactly_once(): void
    {
        // A duplicated "groups.data" entry makes the client append the page
        // twice (12 groups became 36). Inertia::scroll() already appends
        // `data`, so the controller must not append it again.
        for ($i = 0; $i < 30; $i++) {
            $this->role(Company::factory()->create());
        }

        $version = (string) $this->actingAs($this->user)->get(route('roles.index'))->viewData('page')['version'];

        $page = $this->actingAs($this->user)
            ->get(route('roles.index', ['page' => 2]), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'Roles/Index',
                'X-Inertia-Partial-Data' => 'groups',
                'X-Inertia-Infinite-Scroll-Merge-Intent' => 'append',
            ])
            ->assertOk()
            ->json();

        $this->assertSame(['groups.data'], $page['mergeProps']);
        $this->assertSame(['groups.data.id'], $page['matchPropsOn']);
        $this->assertSame($page['props']['groups']['data'][0]['company']['id'], $page['props']['groups']['data'][0]['id']);
        $this->assertCount(12, $page['props']['groups']['data']);
    }

    #[Test]
    public function company_payload_includes_score_and_funding_flag(): void
    {
        $funded = Company::factory()->create(['tier' => 'A', 'funding_last_raised_at' => CarbonImmutable::now()->subMonths(6)]);
        $stale = Company::factory()->create(['funding_last_raised_at' => CarbonImmutable::now()->subMonths(30)]);
        $this->role($funded, ['posted_at' => CarbonImmutable::now()->subDay()]);
        $this->role($stale);

        CompanySnapshot::factory()->create(['company_id' => $funded->id, 'snapshot_date' => CarbonImmutable::now()->subDays(5), 'quality_score' => 40]);
        CompanySnapshot::factory()->create(['company_id' => $funded->id, 'snapshot_date' => CarbonImmutable::now()->subDay(), 'quality_score' => 77]);

        $this->page()
            ->where('groups.data.0.company.id', $funded->id)
            ->where('groups.data.0.company.tier', 'A')
            ->where('groups.data.0.company.quality_score', 77)
            ->where('groups.data.0.company.funded_recently', true)
            ->where('groups.data.1.company.quality_score', null)
            ->where('groups.data.1.company.funded_recently', false);
    }

    #[Test]
    public function new_this_week_counts_recently_created_roles(): void
    {
        $company = Company::factory()->create();
        $this->role($company);
        $old = $this->role($company);
        $old->forceFill(['created_at' => CarbonImmutable::now()->subDays(20)])->save();

        $this->page()
            ->where('groups.data.0.total_matching', 2)
            ->where('groups.data.0.new_this_week', 1);
    }

    #[Test]
    public function role_items_expose_the_contract_fields_and_no_raw(): void
    {
        $company = Company::factory()->create();
        $this->role($company, [
            'source' => 'teamtailor',
            'raw' => ['description' => '<p>Hello</p>'],
            'comp_min' => 100,
            'comp_max' => 200,
            'comp_currency' => 'EUR',
            'comp_interval' => 'year',
        ]);

        $this->page()
            ->has('groups.data.0.roles.0', fn (Assert $role) => $role
                ->hasAll(['id', 'title', 'url', 'location', 'workplace_type', 'department', 'team', 'comp_min', 'comp_max', 'comp_currency', 'comp_interval', 'posted_at', 'created_at', 'source', 'pipeline_status', 'dismissed_at'])
                ->where('has_description', true)
                ->where('comp_currency', 'EUR')
                ->where('source', 'teamtailor')
                ->missing('raw'));
    }

    #[Test]
    public function has_description_is_false_for_sources_without_text(): void
    {
        $company = Company::factory()->create();
        $this->role($company, ['source' => 'workday', 'raw' => ['x' => 1]]);

        $this->page()->where('groups.data.0.roles.0.has_description', false);
    }

    #[Test]
    public function excludes_graveyard_companies_and_closed_roles(): void
    {
        $live = Company::factory()->create();
        $tierGrave = Company::factory()->create(['tier' => 'graveyard']);
        $statusGrave = Company::factory()->create(['status' => 'graveyard']);
        $this->role($live);
        $this->role($live, ['is_open' => false]);
        $this->role($tierGrave);
        $this->role($statusGrave);

        $this->page()
            ->has('groups.data', 1)
            ->where('groups.data.0.total_matching', 1)
            ->where('counts.open', 1);
    }

    #[Test]
    public function dismissed_tab_lists_only_dismissed_roles_and_counts_both_tabs(): void
    {
        $company = Company::factory()->create();
        $this->role($company, ['title' => 'Kept']);
        $this->role($company, ['title' => 'Gone', 'dismissed_at' => CarbonImmutable::now()]);

        $this->page()
            ->where('filters.status', 'open')
            ->has('groups.data.0.roles', 1)
            ->where('groups.data.0.roles.0.title', 'Kept')
            ->where('counts', ['open' => 1, 'dismissed' => 1]);

        $this->page(['status' => 'dismissed'])
            ->where('filters.status', 'dismissed')
            ->has('groups.data.0.roles', 1)
            ->where('groups.data.0.roles.0.title', 'Gone')
            ->whereNot('groups.data.0.roles.0.dismissed_at', null)
            ->where('counts', ['open' => 1, 'dismissed' => 1]);
    }

    #[Test]
    public function search_filters_by_title_case_insensitively(): void
    {
        $company = Company::factory()->create();
        $this->role($company, ['title' => 'Senior Rust Engineer']);
        $this->role($company, ['title' => 'Designer']);

        $this->page(['q' => 'rust'])
            ->where('filters.q', 'rust')
            ->where('groups.data.0.total_matching', 1)
            ->where('groups.data.0.roles.0.title', 'Senior Rust Engineer')
            ->where('counts.open', 1);
    }

    #[Test]
    public function search_treats_like_wildcards_literally(): void
    {
        $company = Company::factory()->create();
        $this->role($company, ['title' => 'Engineer']);

        $this->page(['q' => '%'])->has('groups.data', 0);
    }

    #[Test]
    public function tier_filter(): void
    {
        $a = Company::factory()->create(['tier' => 'A']);
        $c = Company::factory()->create(['tier' => 'C']);
        $this->role($a);
        $this->role($c);

        $this->page(['tier' => 'A'])
            ->has('groups.data', 1)
            ->where('groups.data.0.company.id', $a->id)
            ->where('counts.open', 1);
    }

    #[Test]
    public function workplace_filter(): void
    {
        $company = Company::factory()->create();
        $this->role($company, ['title' => 'Hybrid one', 'workplace_type' => 'hybrid']);
        $this->role($company, ['title' => 'Remote one', 'workplace_type' => 'remote']);

        $this->page(['workplace' => 'hybrid'])
            ->has('groups.data.0.roles', 1)
            ->where('groups.data.0.roles.0.title', 'Hybrid one');
    }

    #[Test]
    public function posted_filter_uses_posted_at_then_created_at(): void
    {
        $company = Company::factory()->create();
        $this->role($company, ['title' => 'Fresh', 'posted_at' => CarbonImmutable::now()->subDays(3)]);
        $this->role($company, ['title' => 'Month old', 'posted_at' => CarbonImmutable::now()->subDays(20)]);
        $this->role($company, ['title' => 'Ancient', 'posted_at' => CarbonImmutable::now()->subDays(90)]);
        $undated = $this->role($company, ['title' => 'Undated, just seen', 'posted_at' => null]);

        $this->page(['posted' => '7d'])
            ->has('groups.data.0.roles', 2)
            ->where('groups.data.0.roles.0.title', 'Undated, just seen')
            ->where('groups.data.0.roles.1.title', 'Fresh');

        $this->page(['posted' => '30d'])->where('groups.data.0.total_matching', 3);
        $this->assertNotNull($undated->id);
    }

    #[Test]
    public function company_filter_narrows_to_one_company(): void
    {
        $one = Company::factory()->create();
        $two = Company::factory()->create();
        $this->role($one);
        $this->role($two);

        $this->page(['company' => $two->id])
            ->has('groups.data', 1)
            ->where('groups.data.0.company.id', $two->id)
            ->where('filters.company', $two->id);
    }

    #[Test]
    public function invalid_filter_values_normalize_to_defaults(): void
    {
        $this->role(Company::factory()->create());

        $this->page(['status' => 'bogus', 'tier' => 'Z', 'workplace' => 'moon', 'posted' => '1y', 'company' => 'abc', 'q' => '   '])
            ->where('filters', [
                'status' => 'open',
                'q' => null,
                'tier' => null,
                'workplace' => null,
                'posted' => null,
                'company' => null,
            ])
            ->has('groups.data', 1);
    }

    #[Test]
    public function exposes_filter_options(): void
    {
        $this->page()
            ->where('options.tiers', ['A', 'B', 'C'])
            ->where('options.posted', [
                ['value' => '7d', 'label' => 'Last 7 days'],
                ['value' => '30d', 'label' => 'Last 30 days'],
            ])
            ->has('options.workplaces', 3);
    }

    #[Test]
    public function counts_ignore_the_status_filter_but_respect_the_others(): void
    {
        $a = Company::factory()->create(['tier' => 'A']);
        $b = Company::factory()->create(['tier' => 'B']);
        $this->role($a);
        $this->role($a, ['dismissed_at' => now()]);
        $this->role($b);
        $this->role($b);

        $this->page(['tier' => 'A', 'status' => 'dismissed'])
            ->where('counts', ['open' => 1, 'dismissed' => 1]);
    }

    #[Test]
    public function other_tenants_roles_are_not_listed(): void
    {
        $this->role(Company::factory()->create());

        $other = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($other);
        $this->role(Company::factory()->create());
        app(TenantContext::class)->setCurrent(Tenant::query()->oldest('id')->firstOrFail());

        $this->page()->has('groups.data', 1)->where('counts.open', 1);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->get(route('roles.index'))->assertRedirect();
    }
}
