<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\CreateAdminUserSeeder;
use Database\Seeders\CreateInitialTenantSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExampleCompaniesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic env for seeders.
        $_ENV['INITIAL_TENANT_NAME'] = 'VantageJobs';
        $_ENV['INITIAL_TENANT_SLUG'] = 'default';
        $_ENV['INITIAL_TENANT_TIMEZONE'] = 'Europe/Skopje';
        $_ENV['INITIAL_USER_NAME'] = 'Admin';
        $_ENV['INITIAL_USER_EMAIL'] = 'admin@vantagejobs.test';
        $_ENV['INITIAL_USER_PASSWORD'] = 'seeded-test-password-123';
    }

    #[Test]
    public function create_initial_tenant_seeder_is_idempotent(): void
    {
        $this->seed(CreateInitialTenantSeeder::class);
        $this->seed(CreateInitialTenantSeeder::class);

        $this->assertSame(1, Tenant::query()->count());

        $tenant = Tenant::query()->sole();
        $this->assertSame('VantageJobs', $tenant->name);
        $this->assertSame('default', $tenant->slug);
        $this->assertSame('Europe/Skopje', $tenant->timezone);
    }

    #[Test]
    public function create_admin_user_seeder_is_idempotent(): void
    {
        $this->seed(CreateInitialTenantSeeder::class);
        $this->seed(CreateAdminUserSeeder::class);
        $this->seed(CreateAdminUserSeeder::class);

        $this->assertSame(1, User::withoutTenantScope()->count());

        $user = User::withoutTenantScope()->sole();
        $this->assertSame('admin@vantagejobs.test', $user->email);
        $this->assertSame('Admin', $user->name);
    }

    #[Test]
    public function example_companies_seeder_is_idempotent(): void
    {
        $this->seed(CreateInitialTenantSeeder::class);
        $this->seed(ExampleCompaniesSeeder::class);
        $this->seed(ExampleCompaniesSeeder::class);

        $this->assertSame(10, Company::withoutTenantScope()->count());

        // Spot-check a few real slugs.
        $stripe = Company::withoutTenantScope()->where('domain', 'stripe.com')->sole();
        $this->assertSame('greenhouse', $stripe->ats_type);
        $this->assertSame('stripe', $stripe->ats_slug);
        $this->assertSame('offer', $stripe->status);

        // Seeded statuses span the lifecycle so dev screens exercise every state.
        $statuses = Company::withoutTenantScope()->pluck('status')->unique();
        $this->assertGreaterThanOrEqual(5, $statuses->count());
        $this->assertSame(
            1,
            Company::withoutTenantScope()->where('tier', 'graveyard')->count(),
        );

        $linear = Company::withoutTenantScope()->where('domain', 'linear.app')->sole();
        $this->assertSame('ashby', $linear->ats_type);
    }

    #[Test]
    public function full_db_seed_chain_creates_expected_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(1, User::withoutTenantScope()->count());
        $this->assertSame(10, Company::withoutTenantScope()->count());

        // The chain must reset tenant context after running.
        $this->assertNull(app(TenantContext::class)->current());
    }
}
