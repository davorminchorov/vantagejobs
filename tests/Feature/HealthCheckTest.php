<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function up_endpoint_is_public_and_returns_ok(): void
    {
        $this->get('/up')
            ->assertOk();
    }

    #[Test]
    public function health_endpoint_requires_auth(): void
    {
        $this->get('/health')->assertRedirect('/login');
    }

    #[Test]
    public function health_endpoint_returns_database_status_when_authenticated(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'health-check']);
        app(TenantContext::class)->setCurrent($tenant);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => [
                    'database' => ['ok' => true, 'error' => null],
                ],
            ]);
    }
}
