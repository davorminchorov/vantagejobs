<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySnapshot>
 */
final class CompanySnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'snapshot_date' => CarbonImmutable::now()->toDateString(),
            'open_role_count' => fake()->numberBetween(0, 50),
            'listings_count' => fake()->numberBetween(0, 50),
            'tier' => fake()->randomElement(['A', 'B', 'C']),
            'status' => 'researching',
            'github_score' => null,
            'blog_posts_90d' => null,
            'has_recent_layoff_event' => false,
            'quality_score' => null,
            'breakdown' => null,
        ];
    }
}
