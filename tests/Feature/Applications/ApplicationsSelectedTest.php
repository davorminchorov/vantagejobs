<?php

declare(strict_types=1);

namespace Tests\Feature\Applications;

use App\Enums\PipelineStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Offer;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApplicationsSelectedTest extends TestCase
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
    public function selected_renders_role_application_with_company_and_offer(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $role = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Engineer',
            'pipeline_status' => PipelineStatus::APPLIED->value,
            'next_action' => 'Email recruiter',
        ]);
        Offer::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Applications/Index')
                ->where('filters.role', $role->id)
                ->where('selected.in_pipeline', true)
                ->where('selected.role.title', 'Staff Engineer')
                ->where('selected.role.pipeline_status', 'applied')
                ->where('selected.role.company.name', 'Acme')
                ->where('selected.role.application.next_action', 'Email recruiter')
                ->where('selected.role.has_offer', true)
                ->where('selected.role.offer.role_id', $role->id)
                ->has('selected.role.offer.currency')
            );
    }

    #[Test]
    public function selected_exposes_null_offer_when_none_recorded(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.role.has_offer', false)
                ->where('selected.role.offer', null)
            );
    }

    #[Test]
    public function selected_groups_stage_notes_by_stage_newest_first(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN->value]);

        RoleStageNote::factory()->create(['role_id' => $role->id, 'stage' => PipelineStatus::APPLIED->value, 'body' => 'submitted via referral', 'created_at' => now()->subDays(3)]);
        RoleStageNote::factory()->create(['role_id' => $role->id, 'stage' => PipelineStatus::SCREEN->value, 'body' => 'older screen note', 'created_at' => now()->subDays(2)]);
        RoleStageNote::factory()->create(['role_id' => $role->id, 'stage' => PipelineStatus::SCREEN->value, 'body' => 'newer screen note', 'created_at' => now()->subHour()]);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('selected.role.stage_notes.applied', 1)
                ->has('selected.role.stage_notes.screen', 2)
                ->where('selected.role.stage_notes.screen.0.body', 'newer screen note')
                ->where('selected.role.stage_notes.screen.1.body', 'older screen note')
            );
    }

    #[Test]
    public function selected_includes_every_tenant_contact_as_referral_option(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]);
        $elsewhere = Company::factory()->create();
        Contact::factory()->create(['name' => 'Zed Referral', 'company_id' => $elsewhere->id]);
        Contact::factory()->withoutCompany()->create(['name' => 'Ann Referral']);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('selected.referral_options', 2)
                ->where('selected.referral_options.0.name', 'Ann Referral')
                ->where('selected.referral_options.1.name', 'Zed Referral')
                ->has('selected.referral_options.0.role_title')
            );
    }

    #[Test]
    public function selected_contacts_are_the_company_contacts_never_touched_first_then_oldest(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => PipelineStatus::APPLIED]);

        Contact::factory()->touchedDaysAgo(5)->create(['company_id' => $company->id, 'name' => 'Recent Rae']);
        Contact::factory()->touchedDaysAgo(40)->create(['company_id' => $company->id, 'name' => 'Older Oprah']);
        Contact::factory()->create(['company_id' => $company->id, 'name' => 'Never Nora', 'last_touch' => null]);
        Contact::factory()->stale()->create(['name' => 'Other Company Olly']);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('selected.contacts', 3)
                ->where('selected.contacts.0.name', 'Never Nora')
                ->where('selected.contacts.0.days_since_touch', null)
                ->where('selected.contacts.0.last_touch', null)
                ->where('selected.contacts.1.name', 'Older Oprah')
                ->where('selected.contacts.1.days_since_touch', 40)
                ->where('selected.contacts.2.name', 'Recent Rae')
                ->where('selected.contacts.2.days_since_touch', 5)
                ->has('selected.contacts.2.email')
                ->has('selected.contacts.2.linkedin_url')
                ->has('selected.contacts.2.role_title')
            );
    }

    #[Test]
    public function selected_events_are_this_roles_upcoming_soonest_first_then_past_newest_first(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN]);
        $other = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN]);

        CalendarEvent::factory()->forRole($role)->create(['title' => 'Later', 'starts_at' => now()->addDays(9)]);
        CalendarEvent::factory()->forRole($role)->create(['title' => 'Old', 'starts_at' => now()->subDays(9)]);
        CalendarEvent::factory()->forRole($role)->create(['title' => 'Soon', 'starts_at' => now()->addDays(1)]);
        CalendarEvent::factory()->forRole($role)->create(['title' => 'Recent', 'starts_at' => now()->subDays(1)]);
        CalendarEvent::factory()->forRole($other)->create(['title' => 'Someone else', 'starts_at' => now()->addDays(2)]);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('selected.events', 4)
                ->where('selected.events.0.title', 'Soon')
                ->where('selected.events.1.title', 'Later')
                ->where('selected.events.2.title', 'Recent')
                ->where('selected.events.3.title', 'Old')
                ->where('selected.events.0.owner.role_id', $role->id)
            );
    }

    #[Test]
    public function selected_carries_the_attention_reason(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()->subDays(30)]);
        $healthy = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'applied_at' => now()]);

        $this->actingAs($this->user)->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected.attention', 'quiet'));
        $this->actingAs($this->user)->get("/applications?role={$healthy->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected.attention', null));
    }

    #[Test]
    public function selected_for_a_role_outside_the_pipeline_returns_info_with_in_pipeline_false(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $role = Role::factory()->create(['company_id' => $company->id, 'title' => 'Designer', 'pipeline_status' => null]);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.in_pipeline', false)
                ->where('selected.attention', null)
                ->where('selected.role.title', 'Designer')
                ->where('selected.role.pipeline_status', null)
                ->where('selected.role.company.name', 'Acme')
                ->has('applications', 0)
            );
    }

    #[Test]
    public function selected_is_null_for_an_unknown_role(): void
    {
        $this->actingAs($this->user)
            ->get('/applications?role=999999')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.role', 999999)
                ->where('selected', null)
            );
    }

    #[Test]
    public function selected_is_null_for_another_tenants_role(): void
    {
        $other = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $other,
            fn () => Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]),
        );

        $this->actingAs($this->user)
            ->get("/applications?role={$foreign->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected', null)->has('applications', 0));
    }

    #[Test]
    public function selected_does_not_leak_other_tenant_contacts_or_events(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($other, function () use ($role): void {
            Contact::factory()->create(['company_id' => $role->company_id]);
            CalendarEvent::factory()->create(['owner_type' => 'role', 'owner_id' => $role->id, 'starts_at' => now()->addDay()]);
        });

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('selected.contacts', 0)
                ->has('selected.referral_options', 0)
                ->has('selected.events', 0)
            );
    }

    #[Test]
    public function partial_reload_of_selected_returns_only_the_panel(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED, 'title' => 'Partial']);

        $this->actingAs($this->user)
            ->get("/applications?role={$role->id}", [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Applications/Index',
                'X-Inertia-Partial-Data' => 'selected',
            ])
            ->assertOk()
            ->assertJsonPath('props.selected.role.title', 'Partial')
            ->assertJsonMissingPath('props.applications');
    }
}
