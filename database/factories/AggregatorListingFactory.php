<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AggregatorListing>
 */
final class AggregatorListingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = fake()->company();
        $title = fake()->randomElement(['Staff Engineer', 'Senior Backend Engineer', 'Full-Stack Engineer']);
        $compMin = fake()->numberBetween(100_000, 180_000);
        $compMax = $compMin + fake()->numberBetween(20_000, 80_000);

        return [
            'source_id' => AggregatorSource::factory(),
            'external_id' => (string) fake()->unique()->numerify('#########'),
            'url' => fake()->url(),
            'raw' => ['title' => $title, 'company' => $company],
            'parsed' => null,
            'parsed_company_name' => $company,
            'parsed_role_title' => $title,
            'parsed_seniority' => 'senior',
            'parsed_remote_type' => 'remote',
            'parsed_comp_min_usd' => $compMin,
            'parsed_comp_max_usd' => $compMax,
            'has_comp' => true,
            'stack_tags' => ['python', 'postgres'],
            'normalized_company_key' => strtolower(preg_replace('/[^a-z0-9]/i', '', $company) ?? ''),
            'normalized_role_key' => strtolower(preg_replace('/[^a-z0-9]/i', '', $title) ?? ''),
            'location_bucket' => 'worldwide',
            'status' => AggregatorListingStatus::NEW,
            'matched_company_id' => null,
            'duplicate_of_id' => null,
            'posted_at' => CarbonImmutable::now()->subDays(fake()->numberBetween(0, 14)),
            'fetched_at' => CarbonImmutable::now(),
            'snoozed_until' => null,
        ];
    }
}
