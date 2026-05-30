<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => ['enabled' => true],
        ];
    }

    public function key(string $key, mixed $value): self
    {
        return $this->state(fn (): array => [
            'key' => $key,
            'value' => $value,
        ]);
    }
}
