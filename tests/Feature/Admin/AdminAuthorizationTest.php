<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function non_admin_user_gets_403_on_admin_runs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/runs')->assertForbidden();
    }

    #[Test]
    public function admin_user_gets_200_on_admin_runs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/runs')->assertOk();
    }

    #[Test]
    public function shared_auth_user_is_admin_is_false_for_non_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', false));
    }

    #[Test]
    public function shared_auth_user_is_admin_is_true_for_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', true));
    }
}
