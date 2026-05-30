<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Enums\PipelineStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContactCrudTest extends TestCase
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
    public function index_page_renders_with_contacts(): void
    {
        Contact::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Contacts/Index')
                ->has('contacts.data', 3)
                ->has('options.companies'));
    }

    #[Test]
    public function index_requires_authentication(): void
    {
        $this->get('/contacts')->assertRedirect('/login');
    }

    #[Test]
    public function store_creates_a_contact(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->user)
            ->post('/contacts', [
                'name' => 'Jane Doe',
                'role_title' => 'VP Engineering',
                'email' => 'jane@acme.com',
                'linkedin_url' => 'https://www.linkedin.com/in/janedoe',
                'company_id' => $company->id,
            ])
            ->assertRedirect();

        $contact = Contact::query()->where('name', 'Jane Doe')->sole();
        $this->assertSame('VP Engineering', $contact->role_title);
        $this->assertSame('jane@acme.com', $contact->email);
        $this->assertSame($company->id, $contact->company_id);
        $this->assertSame($this->tenant->id, $contact->tenant_id);
    }

    #[Test]
    public function store_allows_a_contact_without_a_company(): void
    {
        $this->actingAs($this->user)
            ->post('/contacts', ['name' => 'Floating Contact'])
            ->assertRedirect();

        $contact = Contact::query()->where('name', 'Floating Contact')->sole();
        $this->assertNull($contact->company_id);
    }

    #[Test]
    public function store_validates_required_name(): void
    {
        $this->actingAs($this->user)
            ->post('/contacts', ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function store_validates_email_and_linkedin_format(): void
    {
        $this->actingAs($this->user)
            ->post('/contacts', [
                'name' => 'Bad Data',
                'email' => 'not-an-email',
                'linkedin_url' => 'not-a-url',
            ])
            ->assertSessionHasErrors(['email', 'linkedin_url']);
    }

    #[Test]
    public function store_rejects_a_company_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->post('/contacts', ['name' => 'Cross Tenant', 'company_id' => $foreign->id])
            ->assertSessionHasErrors('company_id');

        $this->assertDatabaseMissing('contacts', ['name' => 'Cross Tenant']);
    }

    #[Test]
    public function show_page_renders_with_outreach_log(): void
    {
        $contact = Contact::factory()->create(['name' => 'Detail Person']);
        OutreachLogEntry::factory()->count(2)->create(['contact_id' => $contact->id]);

        $this->actingAs($this->user)
            ->get("/contacts/{$contact->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Contacts/Show')
                ->where('contact.name', 'Detail Person')
                ->has('outreach', 2));
    }

    #[Test]
    public function show_returns_404_for_another_tenants_contact(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Contact::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)->get("/contacts/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function update_persists_changes(): void
    {
        $contact = Contact::factory()->create(['name' => 'Old Name', 'role_title' => 'Engineer']);

        $this->actingAs($this->user)
            ->put("/contacts/{$contact->id}", [
                'name' => 'New Name',
                'role_title' => 'Director',
                'email' => 'new@example.com',
            ])
            ->assertRedirect();

        $fresh = $contact->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('Director', $fresh->role_title);
        $this->assertSame('new@example.com', $fresh->email);
    }

    #[Test]
    public function update_blocks_another_tenants_contact(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Contact::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->put("/contacts/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertSame('Theirs', $foreign->fresh()->name);
        });
    }

    #[Test]
    public function destroy_removes_the_contact(): void
    {
        $contact = Contact::factory()->create(['name' => 'Goodbye']);

        $this->actingAs($this->user)
            ->delete("/contacts/{$contact->id}")
            ->assertRedirect('/contacts');

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    #[Test]
    public function destroy_blocks_another_tenants_contact(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Contact::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)->delete("/contacts/{$foreign->id}")->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertNotNull($foreign->fresh());
        });
    }

    #[Test]
    public function needs_followup_is_true_for_stale_contact_with_active_role(): void
    {
        $company = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::APPLIED->value,
        ]);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'last_touch' => now()->subDays(30),
        ]);

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn ($page) => $page->where('contacts.data.0.id', $contact->id)->where('contacts.data.0.needs_followup', true));
    }

    #[Test]
    public function needs_followup_is_false_when_recently_touched(): void
    {
        $company = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::SCREEN->value,
        ]);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'last_touch' => now()->subDay(),
        ]);

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn ($page) => $page->where('contacts.data.0.id', $contact->id)->where('contacts.data.0.needs_followup', false));
    }

    #[Test]
    public function needs_followup_is_false_without_an_active_pipeline_role(): void
    {
        $company = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => PipelineStatus::RESEARCHING->value,
        ]);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'last_touch' => now()->subDays(30),
        ]);

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn ($page) => $page->where('contacts.data.0.id', $contact->id)->where('contacts.data.0.needs_followup', false));
    }
}
