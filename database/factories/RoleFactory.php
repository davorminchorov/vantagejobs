<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'source' => fake()->randomElement(['greenhouse', 'lever', 'ashby']),
            'external_id' => fake()->unique()->uuid(),
            'title' => fake()->randomElement([
                'Senior Backend Engineer',
                'Staff Fullstack Engineer',
                'Principal Platform Engineer',
            ]),
            'url' => 'https://example.com/jobs/'.fake()->uuid(),
            'location' => 'Remote',
            'is_remote' => true,
            'is_open' => true,
            'workplace_type' => 'remote',
            'department' => 'Engineering',
            'team' => null,
            'comp_min' => null,
            'comp_max' => null,
            'comp_currency' => null,
            'comp_interval' => null,
            'raw' => null,
            'posted_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'last_seen_at' => now(),
        ];
    }

    /**
     * A Role auto-linked from an aggregator listing (see
     * AggregatorListingPersister::attemptAutoLink()) rather than fetched
     * from a company's own ATS.
     */
    public function aggregator(): self
    {
        return $this->state(fn (): array => [
            'source' => 'aggregator:'.fake()->randomElement(['remoteok', 'himalayas', 'weworkremotely']),
            'aggregator_listing_id' => null,
        ]);
    }
}
