<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Note;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShowCompanyTest extends TestCase
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
    public function unauthenticated_users_are_redirected(): void
    {
        $company = Company::factory()->create();

        $this->get("/companies/{$company->id}")->assertRedirect('/login');
    }

    #[Test]
    public function show_renders_company_with_overview_roles_and_timeline(): void
    {
        $company = Company::factory()->create(['name' => 'Acme', 'ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        Role::factory()->count(2)->create(['company_id' => $company->id, 'is_open' => true]);
        Role::factory()->create(['company_id' => $company->id, 'is_open' => false]);

        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => null,
            'type' => EventType::COMPANY_ADDED->value,
            'title' => 'Added Acme to watch list',
            'body' => null,
            'occurred_at' => CarbonImmutable::now()->subHour(),
        ]);

        Note::factory()->create([
            'company_id' => $company->id,
            'author_id' => $this->user->id,
            'body' => 'CTO follows me on Mastodon',
        ]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Show')
                ->where('company.name', 'Acme')
                ->where('company.ats_type', 'greenhouse')
                ->where('counts.open_roles', 2)
                ->where('counts.total_roles', 3)
                ->has('roles.data', 3)
                ->has('timeline', 2)
            );
    }

    #[Test]
    public function timeline_orders_events_and_notes_by_recency_desc(): void
    {
        $company = Company::factory()->create();

        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => null,
            'type' => EventType::COMPANY_ADDED->value,
            'title' => 'older event',
            'body' => null,
            'occurred_at' => CarbonImmutable::now()->subDays(2),
        ]);

        Note::factory()->create([
            'company_id' => $company->id,
            'author_id' => $this->user->id,
            'body' => 'recent note',
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.kind', 'note')
                ->where('timeline.1.kind', 'event'));
    }

    #[Test]
    public function show_returns_404_for_other_tenants_company(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)->get("/companies/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function roles_tab_paginates_at_fifteen(): void
    {
        $company = Company::factory()->create();
        Role::factory()->count(20)->create(['company_id' => $company->id]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page
                ->has('roles.data', 15)
                ->where('roles.total', 20));
    }

    #[Test]
    public function roles_tab_filters_by_title_search(): void
    {
        $company = Company::factory()->create();
        Role::factory()->create(['company_id' => $company->id, 'title' => 'Senior Backend Engineer']);
        Role::factory()->create(['company_id' => $company->id, 'title' => 'Product Designer']);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}?roles_q=backend")
            ->assertInertia(fn ($page) => $page
                ->has('roles.data', 1)
                ->where('roles.data.0.title', 'Senior Backend Engineer')
                ->where('roles_filters.q', 'backend')
                ->where('roles_filters.state', null));
    }

    #[Test]
    public function roles_tab_filters_by_state(): void
    {
        $company = Company::factory()->create();
        Role::factory()->create(['company_id' => $company->id, 'is_open' => true]);
        Role::factory()->create(['company_id' => $company->id, 'is_open' => false]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}?roles_state=closed")
            ->assertInertia(fn ($page) => $page
                ->has('roles.data', 1)
                ->where('roles.data.0.is_open', false)
                ->where('roles_filters.q', null)
                ->where('roles_filters.state', 'closed'));
    }

    #[Test]
    public function roles_tab_ignores_an_invalid_state_value(): void
    {
        $company = Company::factory()->create();
        Role::factory()->count(2)->create(['company_id' => $company->id]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}?roles_state=bogus")
            ->assertInertia(fn ($page) => $page
                ->has('roles.data', 2)
                ->where('roles_filters.state', null));
    }

    #[Test]
    public function timeline_items_include_the_related_role_title(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['company_id' => $company->id, 'title' => 'Staff Platform Engineer']);

        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => $role->id,
            'type' => EventType::ROLE_CLOSED->value,
            'title' => 'Role closed',
            'body' => null,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.role_id', $role->id)
                ->where('timeline.0.role_title', 'Staff Platform Engineer'));
    }

    #[Test]
    public function timeline_items_without_a_role_have_a_null_role_title(): void
    {
        $company = Company::factory()->create();

        Note::factory()->create([
            'company_id' => $company->id,
            'author_id' => $this->user->id,
            'body' => 'No role attached',
        ]);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.role_id', null)
                ->where('timeline.0.role_title', null));
    }
}
