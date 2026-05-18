<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeRun>
 */
final class ScrapeRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = CarbonImmutable::now()->subMinutes(fake()->numberBetween(1, 1000));

        return [
            'company_id' => Company::factory(),
            'source' => fake()->randomElement(['greenhouse', 'lever', 'ashby']),
            'status' => 'completed',
            'started_at' => $started,
            'finished_at' => $started->addSeconds(fake()->numberBetween(1, 30)),
            'fetched' => fake()->numberBetween(0, 50),
            'new_count' => fake()->numberBetween(0, 5),
            'closed_count' => fake()->numberBetween(0, 3),
            'errors' => null,
        ];
    }
}
