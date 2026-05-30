<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Enums\ApplicationSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UpdateApplicationTest extends TestCase
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
        $role = Role::factory()->create();

        $this->put("/pipeline/roles/{$role->id}/application", [])->assertRedirect('/login');
    }

    #[Test]
    public function it_updates_application_metadata_general_notes_and_prep(): void
    {
        $contact = Contact::factory()->create();
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->put("/pipeline/roles/{$role->id}/application", [
                'applied_at' => '2026-04-01',
                'application_source' => ApplicationSource::REFERRAL->value,
                'referral_contact_id' => $contact->id,
                'next_action' => 'Send thank-you note',
                'next_action_date' => '2026-04-05',
                'general_notes' => 'Spoke with the hiring manager.',
                'interview_prep' => '## Topics\n- system design',
            ])
            ->assertRedirect("/pipeline/roles/{$role->id}");

        $role->refresh();
        $this->assertSame('2026-04-01', $role->applied_at?->toDateString());
        $this->assertSame(ApplicationSource::REFERRAL, $role->application_source);
        $this->assertSame($contact->id, $role->referral_contact_id);
        $this->assertSame('Send thank-you note', $role->next_action);
        $this->assertSame('2026-04-05', $role->next_action_date?->toDateString());
        $this->assertSame('Spoke with the hiring manager.', $role->general_notes);
        $this->assertSame('## Topics\n- system design', $role->interview_prep);
    }

    #[Test]
    public function metadata_edits_do_not_write_an_event(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->put("/pipeline/roles/{$role->id}/application", [
                'next_action' => 'Follow up',
            ]);

        $this->assertSame(0, Event::query()->count());
    }

    #[Test]
    public function nullable_fields_can_be_cleared(): void
    {
        $role = Role::factory()->create([
            'next_action' => 'old action',
            'general_notes' => 'old notes',
        ]);

        $this->actingAs($this->user)
            ->put("/pipeline/roles/{$role->id}/application", [
                'next_action' => '',
                'general_notes' => '',
            ]);

        $role->refresh();
        $this->assertNull($role->next_action);
        $this->assertNull($role->general_notes);
    }

    #[Test]
    public function it_rejects_an_invalid_application_source(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->from("/pipeline/roles/{$role->id}")
            ->put("/pipeline/roles/{$role->id}/application", [
                'application_source' => 'pigeon',
            ])
            ->assertSessionHasErrors('application_source');
    }

    #[Test]
    public function it_rejects_an_invalid_next_action_date(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->from("/pipeline/roles/{$role->id}")
            ->put("/pipeline/roles/{$role->id}/application", [
                'next_action_date' => 'not-a-date',
            ])
            ->assertSessionHasErrors('next_action_date');
    }

    #[Test]
    public function referral_contact_from_another_tenant_is_rejected(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignContact = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Contact::factory()->create(),
        );

        $role = Role::factory()->create();

        $this->actingAs($this->user)
            ->from("/pipeline/roles/{$role->id}")
            ->put("/pipeline/roles/{$role->id}/application", [
                'referral_contact_id' => $foreignContact->id,
            ])
            ->assertSessionHasErrors('referral_contact_id');
    }

    #[Test]
    public function it_cannot_update_another_tenants_role(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Role::factory()->create(),
        );

        $this->actingAs($this->user)
            ->put("/pipeline/roles/{$foreign->id}/application", [
                'next_action' => 'hack',
            ])
            ->assertNotFound();
    }
}
