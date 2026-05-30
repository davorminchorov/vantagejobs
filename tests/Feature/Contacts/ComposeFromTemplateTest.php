<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Template;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ComposeFromTemplateTest extends TestCase
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
    public function it_renders_a_template_against_the_contact(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Corp']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'name' => 'Ada Lovelace',
            'role_title' => 'Staff Engineer',
        ]);
        $template = Template::factory()->create([
            'body' => 'Hi {{contact_first_name}} {{contact_last_name}}, {{company}} {{role}}.',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/contacts/{$contact->id}/compose", ['template_id' => $template->id]);

        $response->assertOk();
        $response->assertJsonPath('rendered', 'Hi Ada Lovelace, Acme Corp Staff Engineer.');
        $response->assertJsonPath('template.id', $template->id);
        $response->assertJsonPath('variables.contact_first_name', 'Ada');
        $response->assertJsonPath('variables.contact_last_name', 'Lovelace');
        $response->assertJsonPath('variables.company', 'Acme Corp');
        $response->assertJsonPath('variables.role', 'Staff Engineer');
    }

    #[Test]
    public function it_leaves_unknown_tokens_in_place(): void
    {
        $contact = Contact::factory()->withoutCompany()->create(['name' => 'Grace Hopper']);
        $template = Template::factory()->create([
            'body' => 'Hi {{contact_first_name}}, {{not_a_token}} stays.',
        ]);

        $this->actingAs($this->user)
            ->postJson("/contacts/{$contact->id}/compose", ['template_id' => $template->id])
            ->assertOk()
            ->assertJsonPath('rendered', 'Hi Grace, {{not_a_token}} stays.');
    }

    #[Test]
    public function single_word_name_yields_null_last_name_and_company_role_blank(): void
    {
        $contact = Contact::factory()->withoutCompany()->create([
            'name' => 'Cher',
            'role_title' => null,
        ]);
        $template = Template::factory()->create([
            'body' => '[{{contact_first_name}}][{{contact_last_name}}][{{company}}][{{role}}]',
        ]);

        $this->actingAs($this->user)
            ->postJson("/contacts/{$contact->id}/compose", ['template_id' => $template->id])
            ->assertOk()
            ->assertJsonPath('rendered', '[Cher][][][]')
            ->assertJsonPath('variables.contact_last_name', null);
    }

    #[Test]
    public function it_requires_a_template_id(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/contacts/{$contact->id}/compose", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template_id');
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $contact = Contact::factory()->create();
        $template = Template::factory()->create();

        $this->post("/contacts/{$contact->id}/compose", ['template_id' => $template->id])
            ->assertRedirect('/login');
    }

    #[Test]
    public function unknown_contact_returns_404(): void
    {
        $template = Template::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/contacts/999999/compose', ['template_id' => $template->id])
            ->assertNotFound();
    }

    #[Test]
    public function unknown_template_returns_404(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/contacts/{$contact->id}/compose", ['template_id' => 999999])
            ->assertNotFound();
    }

    #[Test]
    public function it_cannot_render_a_contact_from_another_tenant(): void
    {
        $template = Template::factory()->create();

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $foreignContact = Contact::factory()->create(['name' => 'Foreign Person']);

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->postJson("/contacts/{$foreignContact->id}/compose", ['template_id' => $template->id])
            ->assertNotFound();
    }

    #[Test]
    public function it_cannot_render_a_template_from_another_tenant(): void
    {
        $contact = Contact::factory()->create();

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $foreignTemplate = Template::factory()->create();

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->postJson("/contacts/{$contact->id}/compose", ['template_id' => $foreignTemplate->id])
            ->assertNotFound();
    }
}
