<?php

declare(strict_types=1);

namespace Tests\Feature\Templates;

use App\Enums\TemplateCategory;
use App\Models\Template;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TemplateCrudTest extends TestCase
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
    public function index_requires_authentication(): void
    {
        $this->get('/templates')->assertRedirect('/login');
    }

    #[Test]
    public function index_renders_templates_ordered_by_category_then_name(): void
    {
        Template::factory()->create(['name' => 'Zeta', 'category' => TemplateCategory::COLD_INTRO]);
        Template::factory()->create(['name' => 'Alpha', 'category' => TemplateCategory::COLD_INTRO]);
        Template::factory()->create(['name' => 'Beta', 'category' => TemplateCategory::FOLLOW_UP]);

        $this->actingAs($this->user)
            ->get('/templates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Templates/Index')
                ->has('templates', 3)
                ->has('categories', count(TemplateCategory::cases()))
                ->where('templates.0.category', TemplateCategory::COLD_INTRO->value)
                ->where('templates.0.name', 'Alpha')
                ->where('templates.1.name', 'Zeta')
                ->where('templates.2.category', TemplateCategory::FOLLOW_UP->value)
                ->where('templates.2.name', 'Beta'),
            );
    }

    #[Test]
    public function index_only_lists_current_tenant_templates(): void
    {
        Template::factory()->create(['name' => 'Mine']);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Template::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->get('/templates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('templates', 1)
                ->where('templates.0.name', 'Mine'),
            );
    }

    #[Test]
    public function store_creates_a_template_for_the_current_tenant(): void
    {
        $this->actingAs($this->user)
            ->post('/templates', [
                'name' => 'Cold intro',
                'category' => TemplateCategory::COLD_INTRO->value,
                'body' => 'Hi {{contact_first_name}},',
            ])
            ->assertRedirect('/templates');

        $template = Template::query()->where('name', 'Cold intro')->sole();
        $this->assertSame(TemplateCategory::COLD_INTRO, $template->category);
        $this->assertSame('Hi {{contact_first_name}},', $template->body);
        $this->assertSame($this->tenant->id, $template->tenant_id);
    }

    #[Test]
    public function store_validates_required_fields(): void
    {
        $this->actingAs($this->user)
            ->from('/templates')
            ->post('/templates', ['name' => '', 'category' => '', 'body' => ''])
            ->assertRedirect('/templates')
            ->assertSessionHasErrors(['name', 'category', 'body']);
    }

    #[Test]
    public function store_rejects_an_invalid_category(): void
    {
        $this->actingAs($this->user)
            ->from('/templates')
            ->post('/templates', [
                'name' => 'Bad',
                'category' => 'not_a_category',
                'body' => 'Body',
            ])
            ->assertSessionHasErrors('category');
    }

    #[Test]
    public function store_requires_authentication(): void
    {
        $this->post('/templates', [
            'name' => 'Cold intro',
            'category' => TemplateCategory::COLD_INTRO->value,
            'body' => 'Body',
        ])->assertRedirect('/login');

        $this->assertSame(0, Template::query()->count());
    }

    #[Test]
    public function update_persists_changes(): void
    {
        $template = Template::factory()->create([
            'name' => 'Old name',
            'category' => TemplateCategory::COLD_INTRO,
            'body' => 'Old body',
        ]);

        $this->actingAs($this->user)
            ->put("/templates/{$template->id}", [
                'name' => 'New name',
                'category' => TemplateCategory::FOLLOW_UP->value,
                'body' => 'New body',
            ])
            ->assertRedirect('/templates');

        $fresh = $template->fresh();
        $this->assertSame('New name', $fresh->name);
        $this->assertSame(TemplateCategory::FOLLOW_UP, $fresh->category);
        $this->assertSame('New body', $fresh->body);
    }

    #[Test]
    public function update_validates_category(): void
    {
        $template = Template::factory()->create();

        $this->actingAs($this->user)
            ->from('/templates')
            ->put("/templates/{$template->id}", [
                'name' => 'Name',
                'category' => 'bogus',
                'body' => 'Body',
            ])
            ->assertSessionHasErrors('category');
    }

    #[Test]
    public function update_blocks_other_tenants_template(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Template::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->put("/templates/{$foreign->id}", [
                'name' => 'Hijacked',
                'category' => TemplateCategory::COLD_INTRO->value,
                'body' => 'Body',
            ])
            ->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertSame('Theirs', $foreign->fresh()->name);
        });
    }

    #[Test]
    public function destroy_deletes_the_template(): void
    {
        $template = Template::factory()->create();

        $this->actingAs($this->user)
            ->delete("/templates/{$template->id}")
            ->assertRedirect('/templates');

        $this->assertNull(Template::query()->find($template->id));
    }

    #[Test]
    public function destroy_blocks_other_tenants_template(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Template::factory()->create(),
        );

        $this->actingAs($this->user)
            ->delete("/templates/{$foreign->id}")
            ->assertNotFound();

        app(TenantContext::class)->withTenant($otherTenant, function () use ($foreign): void {
            $this->assertNotNull(Template::query()->find($foreign->id));
        });
    }
}
