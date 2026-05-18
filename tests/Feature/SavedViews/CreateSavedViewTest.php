<?php

declare(strict_types=1);

namespace Tests\Feature\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CreateSavedViewTest extends TestCase
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
    public function authenticated_user_can_create_a_saved_view(): void
    {
        $this->actingAs($this->user)
            ->from('/companies')
            ->post('/saved-views', [
                'name' => 'A-tier hiring',
                'entity' => 'companies',
                'filters' => ['tier' => ['A'], 'has_open_roles' => true],
            ])
            ->assertRedirect('/companies');

        $this->assertDatabaseHas('saved_views', [
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'A-tier hiring',
            'entity' => 'companies',
            'is_default' => false,
        ]);

        /** @var SavedView $created */
        $created = SavedView::query()->where('name', 'A-tier hiring')->firstOrFail();
        $this->assertSame(['tier' => ['A'], 'has_open_roles' => true], $created->filters);
        $this->assertSame(1, $created->sort_order);
    }

    #[Test]
    public function saved_views_are_shared_to_the_inertia_response(): void
    {
        SavedView::factory()
            ->forEntity('companies')
            ->create(['user_id' => $this->user->id, 'name' => 'Mine', 'filters' => ['tier' => ['A']]]);

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('savedViews.0.name', 'Mine')
                ->where('savedViews.0.entity', 'companies'));
    }

    #[Test]
    public function saved_views_are_scoped_to_the_authenticated_user(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => User::factory()->create(['tenant_id' => $otherTenant->id]),
        );

        // Re-establish my tenant after the closure swapped it.
        app(TenantContext::class)->setCurrent($this->tenant);

        SavedView::factory()->create(['user_id' => $this->user->id, 'name' => 'Mine']);
        app(TenantContext::class)->withTenant($otherTenant, function () use ($otherUser): void {
            SavedView::factory()->create(['user_id' => $otherUser->id, 'name' => 'Theirs']);
        });

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('savedViews', 1)
                ->where('savedViews.0.name', 'Mine'));
    }

    #[Test]
    public function name_is_required_and_entity_must_be_whitelisted(): void
    {
        $this->actingAs($this->user)
            ->from('/companies')
            ->post('/saved-views', ['entity' => 'companies', 'filters' => []])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->user)
            ->from('/companies')
            ->post('/saved-views', ['name' => 'x', 'entity' => 'evilthings', 'filters' => []])
            ->assertSessionHasErrors('entity');
    }
}
