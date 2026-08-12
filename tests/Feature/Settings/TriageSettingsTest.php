<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TriageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'triage-settings']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function the_triage_page_renders_defaults_when_no_setting_is_stored(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Triage')
                ->where('settings.auto_dismiss_threshold', 70)
                ->where('settings.dismissed_retention_days', 90)
                ->where('settings.listing_expiry_days', 30)
            );
    }

    #[Test]
    public function the_triage_page_renders_stored_overrides(): void
    {
        app(SettingsRepository::class)->setMany([
            SettingKey::AUTO_DISMISS_THRESHOLD => 55,
            SettingKey::DISMISSED_RETENTION_DAYS => 90,
            SettingKey::LISTING_EXPIRY_DAYS => 45,
        ]);

        $this->actingAs($this->user)
            ->get('/settings/triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.auto_dismiss_threshold', 55)
                ->where('settings.dismissed_retention_days', 90)
                ->where('settings.listing_expiry_days', 45)
            );
    }

    #[Test]
    public function updating_persists_each_value_as_a_tenant_scoped_setting(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/triage', [
                'auto_dismiss_threshold' => 60,
                'dismissed_retention_days' => 120,
                'listing_expiry_days' => 21,
            ])
            ->assertRedirect('/settings/triage')
            ->assertSessionHas('success');

        $repository = app(SettingsRepository::class);

        $this->assertSame(60, $repository->get(SettingKey::AUTO_DISMISS_THRESHOLD));
        $this->assertSame(120, $repository->get(SettingKey::DISMISSED_RETENTION_DAYS));
        $this->assertSame(21, $repository->get(SettingKey::LISTING_EXPIRY_DAYS));
    }

    #[Test]
    public function validation_rejects_an_out_of_range_threshold_and_retention(): void
    {
        $this->actingAs($this->user)
            ->from('/settings/triage')
            ->post('/settings/triage', [
                'auto_dismiss_threshold' => 150,
                'dismissed_retention_days' => 0,
                'listing_expiry_days' => 0,
            ])
            ->assertRedirect('/settings/triage')
            ->assertSessionHasErrors(['auto_dismiss_threshold', 'dismissed_retention_days', 'listing_expiry_days']);

        $this->assertSame(0, Setting::query()->count());
    }

    #[Test]
    public function validation_requires_integer_values(): void
    {
        $this->actingAs($this->user)
            ->from('/settings/triage')
            ->post('/settings/triage', [
                'auto_dismiss_threshold' => 'high',
                'dismissed_retention_days' => 'forever',
                'listing_expiry_days' => 'never',
            ])
            ->assertRedirect('/settings/triage')
            ->assertSessionHasErrors(['auto_dismiss_threshold', 'dismissed_retention_days', 'listing_expiry_days']);
    }

    #[Test]
    public function settings_written_by_one_tenant_are_not_visible_to_another(): void
    {
        $this->actingAs($this->user)->post('/settings/triage', [
            'auto_dismiss_threshold' => 60,
            'dismissed_retention_days' => 120,
            'listing_expiry_days' => 21,
        ]);

        $otherTenant = Tenant::factory()->create(['slug' => 'other-triage-tenant']);
        app(TenantContext::class)->setCurrent($otherTenant);

        $this->assertSame(
            70,
            app(SettingsRepository::class)->get(SettingKey::AUTO_DISMISS_THRESHOLD, 70),
        );

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(60, app(SettingsRepository::class)->get(SettingKey::AUTO_DISMISS_THRESHOLD));
    }

    #[Test]
    public function guests_cannot_view_or_update_triage_settings(): void
    {
        $this->get('/settings/triage')->assertRedirect('/login');
        $this->post('/settings/triage', [])->assertRedirect('/login');
    }
}
