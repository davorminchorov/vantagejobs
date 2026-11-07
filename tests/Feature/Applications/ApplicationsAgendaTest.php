<?php

declare(strict_types=1);

namespace Tests\Feature\Applications;

use App\Enums\PipelineStatus;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApplicationsAgendaTest extends TestCase
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
    public function non_upcoming_views_show_the_next_five_events_soonest_first(): void
    {
        foreach (range(1, 7) as $days) {
            CalendarEvent::factory()->create(['starts_at' => now()->addDays($days), 'title' => "Event {$days}"]);
        }
        CalendarEvent::factory()->create(['starts_at' => now()->subDay()]);

        foreach (['/applications', '/applications?view=attention'] as $url) {
            $this->actingAs($this->user)
                ->get($url)
                ->assertInertia(fn ($page) => $page
                    ->has('agenda', 5)
                    ->where('agenda.0.title', 'Event 1')
                    ->where('agenda.4.title', 'Event 5')
                );
        }
    }

    #[Test]
    public function upcoming_view_lists_all_future_events_for_any_owner(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::APPLIED]);
        CalendarEvent::factory()->forRole($role)->create(['starts_at' => now()->addDays(1)]);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(2), 'owner_type' => null, 'owner_id' => null]);
        foreach (range(3, 8) as $days) {
            CalendarEvent::factory()->create(['starts_at' => now()->addDays($days)]);
        }
        CalendarEvent::factory()->create(['starts_at' => now()->subDays(3)]);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming')
            ->assertInertia(fn ($page) => $page
                ->where('filters.view', 'upcoming')
                ->where('filters.past', false)
                ->has('agenda', 8)
                ->where('counts.upcoming', 8)
            );
    }

    #[Test]
    public function upcoming_view_caps_the_agenda_at_two_hundred(): void
    {
        $rows = [];
        foreach (range(1, 205) as $i) {
            $rows[] = [
                'tenant_id' => $this->tenant->id,
                'title' => "Event {$i}",
                'starts_at' => now()->addMinutes($i)->toDateTimeString(),
                'timezone' => 'UTC',
                'type' => 'follow_up',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        CalendarEvent::query()->insert($rows);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming')
            ->assertInertia(fn ($page) => $page->has('agenda', 200)->where('agenda.0.title', 'Event 1'));
    }

    #[Test]
    public function past_flag_lists_only_past_events_newest_first(): void
    {
        CalendarEvent::factory()->create(['starts_at' => now()->subDays(10), 'title' => 'Oldest']);
        CalendarEvent::factory()->create(['starts_at' => now()->subDays(1), 'title' => 'Newest']);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(1), 'title' => 'Future']);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming&past=1')
            ->assertInertia(fn ($page) => $page
                ->where('filters.past', true)
                ->has('agenda', 2)
                ->where('agenda.0.title', 'Newest')
                ->where('agenda.1.title', 'Oldest')
            );
    }

    #[Test]
    public function past_flag_is_ignored_outside_the_upcoming_view(): void
    {
        CalendarEvent::factory()->create(['starts_at' => now()->subDays(1), 'title' => 'Past']);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(1), 'title' => 'Future']);

        $this->actingAs($this->user)
            ->get('/applications?past=1')
            ->assertInertia(fn ($page) => $page
                ->where('filters.past', false)
                ->has('agenda', 1)
                ->where('agenda.0.title', 'Future')
            );
    }

    #[Test]
    public function owners_are_resolved_for_roles_companies_and_contacts(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $pipelineRole = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Engineer',
            'pipeline_status' => PipelineStatus::SCREEN,
        ]);
        $looseRole = Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Designer',
            'pipeline_status' => null,
        ]);
        $contact = Contact::factory()->create(['name' => 'Jordan Rivera', 'company_id' => $company->id]);

        CalendarEvent::factory()->forRole($pipelineRole)->create(['starts_at' => now()->addDays(1)]);
        CalendarEvent::factory()->forRole($looseRole)->create(['starts_at' => now()->addDays(2)]);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(3), 'owner_type' => 'company', 'owner_id' => $company->id]);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(4), 'owner_type' => 'contact', 'owner_id' => $contact->id]);
        CalendarEvent::factory()->create(['starts_at' => now()->addDays(5), 'owner_type' => null, 'owner_id' => null]);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming')
            ->assertInertia(fn ($page) => $page
                ->where('agenda.0.owner_type', 'role')
                ->where('agenda.0.owner', [
                    'type' => 'role',
                    'id' => $pipelineRole->id,
                    'label' => 'Staff Engineer, Acme',
                    'role_id' => $pipelineRole->id,
                    'href' => null,
                ])
                ->where('agenda.1.owner.label', 'Designer, Acme')
                ->where('agenda.1.owner.role_id', null)
                ->where('agenda.2.owner', [
                    'type' => 'company',
                    'id' => $company->id,
                    'label' => 'Acme',
                    'role_id' => null,
                    'href' => "/companies/{$company->id}",
                ])
                ->where('agenda.3.owner', [
                    'type' => 'contact',
                    'id' => $contact->id,
                    'label' => 'Jordan Rivera',
                    'role_id' => null,
                    'href' => "/contacts/{$contact->id}",
                ])
                ->where('agenda.4.owner', null)
                ->where('agenda.4.owner_id', null)
            );
    }

    #[Test]
    public function event_with_a_deleted_owner_has_a_null_owner(): void
    {
        CalendarEvent::factory()->create(['starts_at' => now()->addDay(), 'owner_type' => 'company', 'owner_id' => 999999]);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming')
            ->assertInertia(fn ($page) => $page
                ->where('agenda.0.owner_id', 999999)
                ->where('agenda.0.owner', null)
            );
    }

    #[Test]
    public function agenda_events_carry_the_calendar_event_fields(): void
    {
        CalendarEvent::factory()->create([
            'starts_at' => '2030-06-10 13:30:00',
            'timezone' => 'America/New_York',
            'title' => 'Phone screen',
            'notes' => 'Bring questions',
            'url' => 'https://meet.example.com/x',
            'reminder_minutes' => 15,
            'type' => 'interview',
        ]);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming')
            ->assertInertia(fn ($page) => $page
                ->where('agenda.0.title', 'Phone screen')
                ->where('agenda.0.timezone', 'America/New_York')
                ->where('agenda.0.notes', 'Bring questions')
                ->where('agenda.0.url', 'https://meet.example.com/x')
                ->where('agenda.0.reminder_minutes', 15)
                ->where('agenda.0.type', 'interview')
                ->where('agenda.0.starts_at', '2030-06-10T13:30:00+00:00')
            );
    }

    #[Test]
    public function agenda_owner_resolution_does_not_leak_other_tenants(): void
    {
        $other = Tenant::factory()->create();
        $foreignCompany = app(TenantContext::class)->withTenant($other, fn () => Company::factory()->create(['name' => 'Foreign Co']));

        CalendarEvent::factory()->create(['starts_at' => now()->addDay(), 'owner_type' => 'company', 'owner_id' => $foreignCompany->id]);

        $this->actingAs($this->user)
            ->get('/applications?view=upcoming')
            ->assertInertia(fn ($page) => $page->where('agenda.0.owner', null));
    }
}
