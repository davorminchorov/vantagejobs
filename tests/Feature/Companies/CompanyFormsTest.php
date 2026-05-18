<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompanyFormsTest extends TestCase
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
    public function create_page_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/companies/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Companies/Create')->has('options.ats_types', 3));
    }

    #[Test]
    public function store_creates_a_company_and_emits_company_added_event(): void
    {
        $this->actingAs($this->user)
            ->post('/companies', [
                'name' => 'Acme Robotics',
                'website' => 'https://www.acme.com',
                'careers_url' => 'https://boards.greenhouse.io/acme',
                'ats_type' => 'greenhouse',
                'ats_slug' => 'acme',
                'tier' => 'A',
                'status' => 'watching',
            ])
            ->assertRedirect('/companies');

        $company = Company::query()->where('name', 'Acme Robotics')->sole();
        $this->assertSame('greenhouse', $company->ats_type);
        $this->assertSame('acme', $company->ats_slug);
        $this->assertSame('acme.com', $company->domain, 'Domain derived from website host with www stripped.');
        $this->assertSame('A', $company->tier);

        $this->assertSame(1, Event::query()->where('type', EventType::COMPANY_ADDED->value)->where('company_id', $company->id)->count());
    }

    #[Test]
    public function store_applies_default_tier_and_status_when_omitted(): void
    {
        $this->actingAs($this->user)
            ->post('/companies', ['name' => 'Defaults Inc'])
            ->assertRedirect('/companies');

        $company = Company::query()->where('name', 'Defaults Inc')->sole();
        $this->assertSame('C', $company->tier);
        $this->assertSame('researching', $company->status);
    }

    #[Test]
    public function store_validates_required_name(): void
    {
        $this->actingAs($this->user)
            ->from('/companies/create')
            ->post('/companies', ['name' => ''])
            ->assertRedirect('/companies/create')
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function store_validates_url_format_and_ats_slug_pairing(): void
    {
        $this->actingAs($this->user)
            ->from('/companies/create')
            ->post('/companies', [
                'name' => 'Test',
                'website' => 'not-a-url',
                'ats_type' => 'greenhouse',
                // missing ats_slug -> required_with
            ])
            ->assertSessionHasErrors(['website', 'ats_slug']);
    }

    #[Test]
    public function edit_page_renders_for_own_tenant_company(): void
    {
        $company = Company::factory()->create(['name' => 'Mine']);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Companies/Edit')->where('company.name', 'Mine'));
    }

    #[Test]
    public function edit_page_returns_404_for_other_tenants_company(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)->get("/companies/{$foreign->id}/edit")->assertNotFound();
    }

    #[Test]
    public function update_persists_changes(): void
    {
        $company = Company::factory()->create([
            'name' => 'Old Name',
            'tier' => 'C',
            'status' => 'researching',
            'website' => 'https://old.example.com',
        ]);

        $this->actingAs($this->user)
            ->put("/companies/{$company->id}", [
                'name' => 'New Name',
                'website' => 'https://www.new.example.com',
                'tier' => 'A',
                'status' => 'applied',
            ])
            ->assertRedirect('/companies');

        $fresh = $company->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('A', $fresh->tier);
        $this->assertSame('applied', $fresh->status);
        $this->assertSame('new.example.com', $fresh->domain);
    }

    #[Test]
    public function update_blocks_other_tenants_company(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->put("/companies/{$foreign->id}", ['name' => 'Hijacked', 'tier' => 'A', 'status' => 'applied'])
            ->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertSame('Theirs', $foreign->fresh()->name);
        });
    }

    #[Test]
    public function detect_ats_endpoint_returns_match_for_a_known_url(): void
    {
        $this->actingAs($this->user)
            ->postJson('/companies/detect-ats', ['url' => 'https://jobs.lever.co/figma'])
            ->assertOk()
            ->assertExactJson(['detected' => ['ats_type' => 'lever', 'ats_slug' => 'figma']]);
    }

    #[Test]
    public function detect_ats_endpoint_returns_null_for_unknown_url(): void
    {
        $this->actingAs($this->user)
            ->postJson('/companies/detect-ats', ['url' => 'https://example.com/careers'])
            ->assertOk()
            ->assertExactJson(['detected' => null]);
    }

    #[Test]
    public function detect_ats_endpoint_requires_url(): void
    {
        $this->actingAs($this->user)
            ->postJson('/companies/detect-ats', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }
}
