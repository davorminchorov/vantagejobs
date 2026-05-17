<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SettingsRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create(['slug' => 'wave-e']);
        app(TenantContext::class)->setCurrent($tenant);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function settings_routes_redirect_guests_to_login(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
        $this->get('/settings/password')->assertRedirect('/login');
        $this->get('/settings/two-factor')->assertRedirect('/login');
        $this->get('/settings/sessions')->assertRedirect('/login');
    }

    #[Test]
    public function settings_root_redirects_to_profile(): void
    {
        $this->actingAs($this->user)
            ->get('/settings')
            ->assertRedirect('/settings/profile');
    }

    #[Test]
    public function profile_page_renders_with_current_user(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Profile')
                ->where('user.email', $this->user->email)
            );
    }

    #[Test]
    public function password_page_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/password')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Password'));
    }

    #[Test]
    public function two_factor_page_renders_with_status_flags(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/two-factor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/TwoFactor')
                ->where('two_factor_enabled', false)
                ->where('two_factor_confirmed', false)
            );
    }
}
