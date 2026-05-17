<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CreateInitialTenantSeeder::class,
            CreateAdminUserSeeder::class,
            ExampleCompaniesSeeder::class,
        ]);

        // Reset tenant context so the seeder process leaves no global state.
        app(TenantContext::class)->setCurrent(null);
    }
}
