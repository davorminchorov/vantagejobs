<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
final class SavedViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'entity' => SavedView::ENTITY_COMPANIES,
            'filters' => [],
            'sort_order' => 0,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }

    public function forEntity(string $entity): self
    {
        return $this->state(['entity' => $entity]);
    }
}
