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

final class ReorderTest extends TestCase
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
    public function sort_order_can_be_updated_and_persists(): void
    {
        $view = SavedView::factory()->create(['user_id' => $this->user->id, 'sort_order' => 0]);

        $this->actingAs($this->user)
            ->from('/dashboard')
            ->patch("/saved-views/{$view->id}", ['sort_order' => 5])
            ->assertRedirect('/dashboard');

        $this->assertSame(5, $view->refresh()->sort_order);
    }

    #[Test]
    public function cannot_update_a_view_owned_by_another_user_in_same_tenant(): void
    {
        $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $foreignView = SavedView::factory()->create(['user_id' => $otherUser->id, 'sort_order' => 0]);

        $this->actingAs($this->user)
            ->from('/dashboard')
            ->patch("/saved-views/{$foreignView->id}", ['sort_order' => 99])
            ->assertForbidden();

        $this->assertSame(0, $foreignView->refresh()->sort_order);
    }

    #[Test]
    public function views_in_other_tenants_are_invisible_returning_not_found(): void
    {
        $strangerTenant = Tenant::factory()->create();
        $stranger = app(TenantContext::class)->withTenant(
            $strangerTenant,
            fn () => User::factory()->create(['tenant_id' => $strangerTenant->id]),
        );

        $foreignView = app(TenantContext::class)->withTenant(
            $strangerTenant,
            fn () => SavedView::factory()->create(['user_id' => $stranger->id, 'sort_order' => 0]),
        );

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->from('/dashboard')
            ->patch("/saved-views/{$foreignView->id}", ['sort_order' => 99])
            ->assertNotFound();
    }
}
