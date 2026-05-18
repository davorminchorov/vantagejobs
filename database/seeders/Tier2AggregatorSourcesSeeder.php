<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class Tier2AggregatorSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'default')->firstOrFail();
        app(TenantContext::class)->setCurrent($tenant);

        $types = [
            AggregatorSourceType::JOBICY,
            AggregatorSourceType::ARBEITNOW,
            AggregatorSourceType::PYTHON_ORG,
            AggregatorSourceType::DJANGO_NEWS,
            AggregatorSourceType::ELIXIR_JOBS,
            AggregatorSourceType::HEXHIRE,
        ];

        foreach ($types as $t) {
            $existing = AggregatorSource::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', $t->value)
                ->first();
            if ($existing !== null) {
                $this->command?->info("EXISTS  {$t->value}");

                continue;
            }
            $row = new AggregatorSource();
            $row->tenant_id = $tenant->id;
            $row->type = $t;
            $row->name = $t->displayName();
            $row->enabled = true;
            $row->cadence_minutes = $t->defaultCadenceMinutes();
            $row->consecutive_failures = 0;
            $row->save();
            $this->command?->info("CREATED {$t->value} id={$row->id}");
        }
    }
}
