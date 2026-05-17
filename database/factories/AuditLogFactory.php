<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'actor_id' => null,
            'action' => fake()->randomElement([
                'auth.login.success',
                'auth.login.failed',
                'auth.logout',
                'auth.password.changed',
                'auth.2fa.enabled',
            ]),
            'outcome' => 'success',
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'context' => [],
        ];
    }
}
