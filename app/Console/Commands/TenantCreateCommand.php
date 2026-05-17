<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;

final class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create {--name=} {--slug=} {--timezone=Europe/Skopje}';

    protected $description = 'Create a new tenant. Not surfaced in the UI — kept so future multi-user is a one-line operation.';

    public function handle(): int
    {
        $name = $this->option('name');
        if (! is_string($name) || $name === '') {
            $name = (string) $this->ask('Tenant name');
        }

        $slug = $this->option('slug');
        if (! is_string($slug) || $slug === '') {
            $slug = (string) $this->ask('Tenant slug');
        }

        $timezone = (string) $this->option('timezone');

        if ($name === '' || $slug === '') {
            $this->error('Both name and slug are required.');

            return self::FAILURE;
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            $this->error("A tenant with slug '{$slug}' already exists.");

            return self::FAILURE;
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'timezone' => $timezone,
            'is_active' => true,
        ]);

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'actor_id' => null,
            'action' => 'tenant.created',
            'outcome' => 'success',
            'ip' => null,
            'user_agent' => null,
            'context' => [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
        ]);

        $this->info("Tenant created: {$tenant->name} (slug={$tenant->slug}, id={$tenant->id})");

        return self::SUCCESS;
    }
}
