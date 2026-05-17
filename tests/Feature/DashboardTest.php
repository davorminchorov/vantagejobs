<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function dashboard_shows_seeded_companies_for_current_tenant(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dashboard-test']);
        app(TenantContext::class)->setCurrent($tenant);

        $user = User::factory()->create();
        Company::factory()->count(3)->create();

        // Seed an unrelated tenant's company to prove scoping
        $otherTenant = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            Company::factory()->create(['name' => 'Other Tenant Co']);
        });

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('companies_count', 3)
                ->has('companies', 3)
            );
    }

    #[Test]
    public function dashboard_renders_empty_state_when_no_companies(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'empty-dashboard']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('companies_count', 0)
                ->has('companies', 0)
            );
    }
}
