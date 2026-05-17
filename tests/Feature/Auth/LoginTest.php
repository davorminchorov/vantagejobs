<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->user = User::factory()->forTenant($this->tenant)->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
    }

    #[Test]
    public function user_can_log_in_with_correct_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function user_cannot_log_in_with_wrong_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'definitely-not-the-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function login_throttles_after_five_failed_attempts(): void
    {
        RateLimiter::clear('owner@example.test|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'owner@example.test',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->from('/login')->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'wrong-password',
        ]);

        // Fortify converts a hit limiter into a 429 with a validation error
        // on the email field ("auth.throttle" message).
        $response->assertStatus(429);
        $this->assertGuest();
    }
}
