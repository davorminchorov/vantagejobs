<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AggregatorSource;
use App\Models\ParserConfidenceDaily;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserConfidenceDaily>
 */
final class ParserConfidenceDailyFactory extends Factory
{
    protected $model = ParserConfidenceDaily::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => AggregatorSource::factory(),
            'field' => fake()->randomElement(['company_name', 'role_title', 'location', 'salary']),
            'day' => CarbonImmutable::now()->toDateString(),
            'n' => fake()->numberBetween(10, 200),
            'n_missing' => fake()->numberBetween(0, 20),
            'p10' => fake()->randomFloat(4, 0, 0.5),
            'p50' => fake()->randomFloat(4, 0.5, 0.95),
            'p90' => fake()->randomFloat(4, 0.9, 1),
            'n_corrections' => fake()->numberBetween(0, 5),
        ];
    }

    public function forSource(AggregatorSource $source): self
    {
        return $this->state(fn (): array => ['source_id' => $source->id]);
    }

    public function onDay(string|CarbonImmutable $day): self
    {
        return $this->state(fn (): array => [
            'day' => $day instanceof CarbonImmutable ? $day->toDateString() : $day,
        ]);
    }

    public function withP50(float $p50, string $field = 'role_title'): self
    {
        return $this->state(fn (): array => [
            'field' => $field,
            'p50' => $p50,
        ]);
    }
}
