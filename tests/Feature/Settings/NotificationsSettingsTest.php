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

final class NotificationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'notif-settings']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create(['email' => 'owner@vantagejobs.test']);
    }

    #[Test]
    public function the_notifications_page_renders_config_defaults_when_no_setting_is_stored(): void
    {
        $this->actingAs($this->user)
            ->get('/settings/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Notifications')
                ->where('settings.digest_send_time', config('notifications.digest_send_time'))
                ->where('settings.dnd_start', config('notifications.dnd_start'))
                ->where('settings.dnd_end', config('notifications.dnd_end'))
                ->where('settings.digest_skip_empty', (bool) config('notifications.digest_skip_empty'))
                ->where('settings.notification_email', $this->user->email)
                ->where('settings.weekly_summary_enabled', true)
            );
    }

    #[Test]
    public function the_notifications_page_renders_stored_overrides(): void
    {
        app(SettingsRepository::class)->setMany([
            SettingKey::DIGEST_SEND_TIME => '09:30',
            SettingKey::NOTIFICATION_EMAIL => 'alerts@vantagejobs.test',
            SettingKey::DIGEST_SKIP_EMPTY => false,
            SettingKey::WEEKLY_SUMMARY_ENABLED => false,
        ]);

        $this->actingAs($this->user)
            ->get('/settings/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.digest_send_time', '09:30')
                ->where('settings.notification_email', 'alerts@vantagejobs.test')
                ->where('settings.digest_skip_empty', false)
                ->where('settings.weekly_summary_enabled', false)
            );
    }

    #[Test]
    public function updating_persists_each_value_as_a_tenant_scoped_setting(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/notifications', [
                'digest_send_time' => '08:15',
                'dnd_start' => '23:00',
                'dnd_end' => '06:30',
                'digest_skip_empty' => false,
                'notification_email' => 'alerts@vantagejobs.test',
                'weekly_summary_enabled' => false,
            ])
            ->assertRedirect('/settings/notifications')
            ->assertSessionHas('success');

        $repository = app(SettingsRepository::class);

        $this->assertSame('08:15', $repository->get(SettingKey::DIGEST_SEND_TIME));
        $this->assertSame('23:00', $repository->get(SettingKey::DND_START));
        $this->assertSame('06:30', $repository->get(SettingKey::DND_END));
        $this->assertFalse($repository->get(SettingKey::DIGEST_SKIP_EMPTY));
        $this->assertSame('alerts@vantagejobs.test', $repository->get(SettingKey::NOTIFICATION_EMAIL));
        $this->assertFalse($repository->get(SettingKey::WEEKLY_SUMMARY_ENABLED));
    }

    #[Test]
    public function updating_again_overwrites_rather_than_duplicates_the_setting_row(): void
    {
        $payload = [
            'digest_send_time' => '08:15',
            'dnd_start' => '23:00',
            'dnd_end' => '06:30',
            'digest_skip_empty' => true,
            'notification_email' => 'alerts@vantagejobs.test',
            'weekly_summary_enabled' => true,
        ];

        $this->actingAs($this->user)->post('/settings/notifications', $payload);
        $this->actingAs($this->user)->post('/settings/notifications', [
            ...$payload,
            'digest_send_time' => '10:45',
        ]);

        $rows = Setting::query()->where('key', SettingKey::DIGEST_SEND_TIME)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('10:45', app(SettingsRepository::class)->get(SettingKey::DIGEST_SEND_TIME));
    }

    #[Test]
    public function validation_rejects_a_malformed_time_and_a_bad_email(): void
    {
        $this->actingAs($this->user)
            ->from('/settings/notifications')
            ->post('/settings/notifications', [
                'digest_send_time' => '8am',
                'dnd_start' => '23:00',
                'dnd_end' => '06:30',
                'digest_skip_empty' => false,
                'notification_email' => 'not-an-email',
                'weekly_summary_enabled' => false,
            ])
            ->assertRedirect('/settings/notifications')
            ->assertSessionHasErrors(['digest_send_time', 'notification_email']);

        $this->assertSame(0, Setting::query()->count());
    }

    #[Test]
    public function settings_written_by_one_tenant_are_not_visible_to_another(): void
    {
        $this->actingAs($this->user)->post('/settings/notifications', [
            'digest_send_time' => '08:15',
            'dnd_start' => '23:00',
            'dnd_end' => '06:30',
            'digest_skip_empty' => false,
            'notification_email' => 'alerts@vantagejobs.test',
            'weekly_summary_enabled' => false,
        ]);

        $otherTenant = Tenant::factory()->create(['slug' => 'other-tenant']);
        app(TenantContext::class)->setCurrent($otherTenant);

        // The other tenant has no override, so the repository falls back to config.
        $this->assertSame(
            config('notifications.digest_send_time'),
            app(SettingsRepository::class)->get(SettingKey::DIGEST_SEND_TIME),
        );

        // Restore the original tenant and confirm its override survived.
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame('08:15', app(SettingsRepository::class)->get(SettingKey::DIGEST_SEND_TIME));
    }

    #[Test]
    public function guests_cannot_view_or_update_notification_settings(): void
    {
        $this->get('/settings/notifications')->assertRedirect('/login');
        $this->post('/settings/notifications', [])->assertRedirect('/login');
    }
}
