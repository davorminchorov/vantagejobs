<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuthRole;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = app(TenantContext::class)->current()?->id
            ?? Tenant::factory()->create()->id;

        return [
            'tenant_id' => $tenantId,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'timezone' => 'Europe/Skopje',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $role = AuthRole::firstOrCreate(['name' => 'admin']);
            $user->authRoles()->syncWithoutDetaching([$role->id]);
        });
    }
}
