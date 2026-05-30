<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportJob>
 */
final class ExportJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ExportJobStatus::PENDING,
            'formats' => [ExportFormat::SQL->value, ExportFormat::JSON->value, ExportFormat::CSV->value],
            'manifest' => null,
            'disk' => 'local',
            'path' => null,
            'schema_version' => null,
            'error' => null,
            'expires_at' => null,
        ];
    }

    public function running(): self
    {
        return $this->state(fn (): array => ['status' => ExportJobStatus::RUNNING]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ExportJobStatus::COMPLETED,
            'path' => 'exports/'.fake()->uuid().'.zip',
            'schema_version' => '1',
            'manifest' => ['schema_version' => '1', 'counts' => [], 'generated_at' => now()->toIso8601String()],
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => ExportJobStatus::FAILED,
            'error' => fake()->sentence(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => ExportJobStatus::EXPIRED,
            'expires_at' => now()->subHour(),
        ]);
    }
}
