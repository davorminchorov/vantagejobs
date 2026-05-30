<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineStatus;
use App\Models\Role;
use App\Models\RoleStageNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleStageNote>
 */
final class RoleStageNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'stage' => fake()->randomElement(PipelineStatus::cases()),
            'body' => fake()->paragraph(),
        ];
    }

    public function forStage(PipelineStatus $stage): self
    {
        return $this->state(fn (): array => ['stage' => $stage]);
    }
}
