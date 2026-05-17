<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();

        $this->user = User::factory()->forTenant($tenant)->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('old-password-value'),
        ]);
    }

    #[Test]
    public function user_can_request_a_reset_link(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', [
            'email' => 'owner@example.test',
        ]);

        $response->assertSessionHasNoErrors();
        Notification::assertSentTo($this->user, ResetPassword::class);
    }

    #[Test]
    public function user_can_reset_password_with_valid_token(): void
    {
        $token = Password::broker()->createToken($this->user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'owner@example.test',
            'password' => 'new-password-of-length-12-plus',
            'password_confirmation' => 'new-password-of-length-12-plus',
        ]);

        $response->assertSessionHasNoErrors();

        $this->user->refresh();
        $this->assertTrue(Hash::check('new-password-of-length-12-plus', $this->user->password));
    }
}
