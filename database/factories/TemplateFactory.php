<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TemplateCategory;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
final class TemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(3),
            'category' => fake()->randomElement(TemplateCategory::cases()),
            'body' => "Hi {{contact_first_name}},\n\n".fake()->paragraph()."\n\nBest,\nMe",
        ];
    }

    public function inCategory(TemplateCategory $category): self
    {
        return $this->state(fn (): array => ['category' => $category]);
    }
}
