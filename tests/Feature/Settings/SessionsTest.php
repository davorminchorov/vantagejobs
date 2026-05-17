<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create(['slug' => 'sessions-test']);
        app(TenantContext::class)->setCurrent($tenant);

        $this->user = User::factory()->create([
            'password' => Hash::make('correct-horse-battery-12'),
        ]);
    }

    #[Test]
    public function sessions_page_renders_for_authenticated_users(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/sessions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Sessions'));
    }

    #[Test]
    public function destroy_others_requires_current_password(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/sessions/sign-out-others', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function destroy_others_succeeds_with_correct_password(): void
    {
        // logoutOtherDevices needs a real password to recompute the
        // hashed-remember-token guard salt; the assertion focuses on
        // the redirect + flash contract rather than remember_token
        // rotation (which only happens against a real session store).
        $this->actingAs($this->user)
            ->post('/settings/sessions/sign-out-others', [
                'password' => 'correct-horse-battery-12',
            ])
            ->assertRedirect('/settings/sessions')
            ->assertSessionHas('success');
    }
}
