<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AggregatorSource>
 */
final class AggregatorSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var AggregatorSourceType $type Unique per test: the table has a (tenant_id, type) unique key, so random duplicates flake under parallel runs. */
        $type = fake()->unique()->randomElement(AggregatorSourceType::cases());

        return [
            'type' => $type,
            'name' => $type->displayName(),
            'enabled' => true,
            'cadence_minutes' => $type->defaultCadenceMinutes(),
            'last_run_at' => null,
            'next_run_at' => null,
            'last_error' => null,
            'consecutive_failures' => 0,
        ];
    }

    public function ofType(AggregatorSourceType $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'name' => $type->displayName(),
            'cadence_minutes' => $type->defaultCadenceMinutes(),
        ]);
    }
}
