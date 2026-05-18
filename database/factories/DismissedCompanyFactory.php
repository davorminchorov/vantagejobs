<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DismissedCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DismissedCompany>
 */
final class DismissedCompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $display = fake()->company();

        return [
            'normalized_company_key' => strtolower(preg_replace('/[^a-z0-9]/i', '', $display) ?? ''),
            'display_name' => $display,
            'dismiss_count' => 1,
            'auto_dismiss' => false,
            'first_dismissed_at' => CarbonImmutable::now()->subDays(7),
            'last_dismissed_at' => CarbonImmutable::now(),
        ];
    }

    public function auto(): self
    {
        return $this->state(fn (): array => [
            'auto_dismiss' => true,
            'dismiss_count' => 3,
        ]);
    }
}
