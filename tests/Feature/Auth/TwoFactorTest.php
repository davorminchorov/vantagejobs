<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();

        $this->user = User::factory()->forTenant($tenant)->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
    }

    #[Test]
    public function user_can_enable_two_factor_via_settings_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        // Fortify redirects back after enabling; status varies by request type.
        $this->assertContains($response->status(), [200, 302]);

        $this->user->refresh();
        $this->assertNotNull($this->user->two_factor_secret);
    }

    #[Test]
    public function user_with_two_factor_must_complete_challenge(): void
    {
        // Enable 2FA and mark it as confirmed (Fortify is configured with
        // 'confirm' => true, so the challenge only triggers for confirmed
        // 2FA users — hasEnabledTwoFactorAuthentication() returns false
        // until two_factor_confirmed_at is set).
        $this->actingAs($this->user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        $this->user->refresh();
        $this->assertNotNull($this->user->two_factor_secret);

        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

        auth()->logout();
        session()->flush();

        $response = $this->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }
}
