<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Seeds the five Phase 2 aggregator sources for the current tenant. Idempotent
 * by (tenant_id, type) — re-running keeps display names + cadence in sync with
 * the AggregatorSourceType enum but leaves enabled/last_run state intact.
 */
final class AggregatorSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class)->currentOrFail();

        $created = 0;
        $updated = 0;

        foreach (AggregatorSourceType::cases() as $type) {
            $existed = AggregatorSource::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', $type->value)
                ->exists();

            AggregatorSource::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'type' => $type->value,
                ],
                [
                    'name' => $type->displayName(),
                    'cadence_minutes' => $type->defaultCadenceMinutes(),
                    'enabled' => true,
                ],
            );

            $existed ? $updated++ : $created++;
        }

        $this->command?->getOutput()->writeln(
            "  <info>Aggregator sources:</info> {$created} created, {$updated} updated (".count(AggregatorSourceType::cases()).' total)'
        );
    }
}
