<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationKind;
use App\Models\NotificationLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLogEntry>
 */
final class NotificationLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => fake()->randomElement(NotificationKind::cases()),
            'company_id' => null,
            'recipient' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'context' => [],
            'sent_at' => now(),
        ];
    }

    public function kind(NotificationKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function forCompany(int $companyId): self
    {
        return $this->state(fn (): array => ['company_id' => $companyId]);
    }

    public function sentAt(\DateTimeInterface $sentAt): self
    {
        return $this->state(fn (): array => ['sent_at' => $sentAt]);
    }
}
