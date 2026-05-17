<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = str(fake()->unique()->slug(2))->lower()->toString();

        return [
            'name' => $name,
            'website' => "https://{$slug}.example.com",
            'careers_url' => "https://{$slug}.example.com/careers",
            'domain' => "{$slug}.example.com",
            'ats_type' => fake()->randomElement(['greenhouse', 'lever', 'ashby']),
            'ats_slug' => $slug,
            'tier' => fake()->randomElement(['A', 'B', 'C']),
            'status' => 'researching',
            'last_checked_at' => null,
        ];
    }
}
