<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'role_title' => fake()->jobTitle(),
            'linkedin_url' => 'https://www.linkedin.com/in/'.fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'notes' => null,
            'last_touch' => null,
        ];
    }

    public function withoutCompany(): self
    {
        return $this->state(fn (): array => ['company_id' => null]);
    }

    public function touchedDaysAgo(int $days): self
    {
        return $this->state(fn (): array => ['last_touch' => now()->subDays($days)]);
    }

    public function stale(): self
    {
        return $this->state(fn (): array => ['last_touch' => now()->subDays(30)]);
    }
}
