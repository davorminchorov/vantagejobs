<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\AuditLog;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TenantCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_tenant_from_options(): void
    {
        $this->artisan('tenant:create', [
            '--name' => 'Acme Co',
            '--slug' => 'acme',
            '--timezone' => 'America/New_York',
        ])
            ->expectsOutputToContain('Tenant created: Acme Co')
            ->assertSuccessful();

        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $this->assertSame('Acme Co', $tenant->name);
        $this->assertSame('America/New_York', $tenant->timezone);
        $this->assertTrue((bool) $tenant->is_active);

        $log = AuditLog::query()->where('action', 'tenant.created')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('acme', $log->context['slug']);
        $this->assertSame('Acme Co', $log->context['name']);
    }

    #[Test]
    public function it_rejects_a_duplicate_slug(): void
    {
        Tenant::factory()->create(['slug' => 'taken']);

        $this->artisan('tenant:create', [
            '--name' => 'Other',
            '--slug' => 'taken',
        ])
            ->expectsOutputToContain("tenant with slug 'taken' already exists")
            ->assertExitCode(1);

        $this->assertSame(1, Tenant::query()->where('slug', 'taken')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'tenant.created')->count());
    }
}
