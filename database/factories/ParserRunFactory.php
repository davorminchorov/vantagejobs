<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ParserRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserRun>
 */
final class ParserRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_version' => 2,
            'from_id' => 1,
            'to_id' => null,
            'processed' => 0,
            'force_corrections' => false,
            'source_filter' => null,
            'started_at' => CarbonImmutable::now(),
            'finished_at' => null,
        ];
    }
}
