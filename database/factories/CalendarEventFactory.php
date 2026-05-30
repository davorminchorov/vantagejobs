<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
final class CalendarEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => null,
            'owner_id' => null,
            'title' => fake()->sentence(4),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 30)),
            'timezone' => 'America/New_York',
            'type' => fake()->randomElement(CalendarEventType::cases()),
            'notes' => null,
            'url' => null,
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ];
    }

    public function forRole(Role $role): self
    {
        return $this->state(fn (): array => [
            'owner_type' => 'role',
            'owner_id' => $role->getKey(),
        ]);
    }

    public function due(): self
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addMinutes(10),
            'reminder_minutes' => 30,
            'reminder_sent_at' => null,
        ]);
    }

    public function reminderSent(): self
    {
        return $this->state(fn (): array => ['reminder_sent_at' => now()]);
    }

    public function withoutReminder(): self
    {
        return $this->state(fn (): array => ['reminder_minutes' => null]);
    }
}
