<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
final class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'role_id' => null,
            'type' => fake()->randomElement([
                'new_role',
                'role_closed',
                'funding',
                'layoff',
                'blog_post',
                'my_note',
            ]),
            'title' => fake()->sentence(),
            'body' => null,
            'occurred_at' => now()->subHours(fake()->numberBetween(1, 168)),
        ];
    }
}
