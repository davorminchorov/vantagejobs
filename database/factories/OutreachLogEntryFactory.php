<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OutreachChannel;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachLogEntry>
 */
final class OutreachLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel' => fake()->randomElement(OutreachChannel::cases()),
            'subject' => fake()->optional()->sentence(),
            'body' => fake()->optional()->paragraph(),
            'occurred_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    public function viaChannel(OutreachChannel $channel): self
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }
}
