<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
final class NoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'author_id' => null,
            'body' => fake()->paragraph(),
        ];
    }
}
