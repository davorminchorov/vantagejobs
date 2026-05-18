<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyBlogPost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBlogPost>
 */
final class CompanyBlogPostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publishedAt = CarbonImmutable::now()->subDays(fake()->numberBetween(0, 89));

        return [
            'company_id' => Company::factory(),
            'external_id' => 'https://example.com/blog/'.fake()->slug(),
            'title' => fake()->sentence(),
            'url' => 'https://example.com/blog/'.fake()->slug(),
            'published_at' => $publishedAt,
            'excerpt' => fake()->paragraph(),
            'fetched_at' => CarbonImmutable::now(),
        ];
    }
}
