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
}
