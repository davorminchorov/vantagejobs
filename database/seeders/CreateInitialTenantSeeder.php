<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class CreateInitialTenantSeeder extends Seeder
{
    public function run(): void
    {
        $name = (string) env('INITIAL_TENANT_NAME', 'VantageJobs');
        $slug = (string) env('INITIAL_TENANT_SLUG', 'default');
        $timezone = (string) env('INITIAL_TENANT_TIMEZONE', 'Europe/Skopje');

        $existed = Tenant::query()->where('slug', $slug)->exists();

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'timezone' => $timezone,
                'is_active' => true,
            ],
        );

        app(TenantContext::class)->setCurrent($tenant);

        $verb = $existed ? 'updated' : 'created';
        $this->command?->getOutput()->writeln(
            "  <info>Tenant {$verb}:</info> {$tenant->name} (slug={$tenant->slug}, id={$tenant->id})"
        );
    }
}
