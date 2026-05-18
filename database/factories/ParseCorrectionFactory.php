<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AggregatorListing;
use App\Models\ParseCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParseCorrection>
 */
final class ParseCorrectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => AggregatorListing::factory(),
            'user_id' => User::factory(),
            'field' => 'role_title',
            'old_value' => ['value' => 'Software Engineer'],
            'old_confidence' => 0.6,
            'old_source' => 'inferred',
            'new_value' => ['value' => 'Senior Software Engineer'],
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
