<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
final class SourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'type' => fake()->randomElement(['ats', 'aggregator', 'signal']),
            'enabled' => true,
            'last_run_at' => null,
            'last_error' => null,
        ];
    }
}
