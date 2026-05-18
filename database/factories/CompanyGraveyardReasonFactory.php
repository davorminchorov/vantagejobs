<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyGraveyardReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyGraveyardReason>
 */
final class CompanyGraveyardReasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'demoted_by_user_id' => null,
            'restored_by_user_id' => null,
            'demoted_at' => CarbonImmutable::now(),
            'restored_at' => null,
            'reason' => fake()->sentence(),
            'tags' => fake()->randomElements(CompanyGraveyardReason::PRESET_TAGS, 2),
        ];
    }
}
