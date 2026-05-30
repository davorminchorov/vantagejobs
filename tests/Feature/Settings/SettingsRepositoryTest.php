<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->settings = app(SettingsRepository::class);
    }

    #[Test]
    public function get_returns_config_default_when_no_row_exists(): void
    {
        config()->set('notifications.digest_send_time', '07:00');

        $this->assertSame('07:00', $this->settings->get('digest_send_time'));
    }

    #[Test]
    public function get_resolves_defaults_from_non_notifications_config_files(): void
    {
        config()->set('sources.consecutive_failure_alert', 3);
        config()->set('pipeline.follow_up_threshold_days', 7);
        config()->set('export.download_ttl_hours', 24);

        $this->assertSame(3, $this->settings->get('consecutive_failure_alert'));
        $this->assertSame(7, $this->settings->get('auto_dismiss_threshold_days'));
        $this->assertSame(24, $this->settings->get('export_download_ttl_hours'));
    }

    #[Test]
    public function get_falls_back_to_caller_default_for_unknown_key(): void
    {
        $this->assertSame('fallback', $this->settings->get('not_a_real_key', 'fallback'));
        $this->assertNull($this->settings->get('not_a_real_key'));
    }

    #[Test]
    public function db_override_takes_precedence_over_config_default(): void
    {
        config()->set('notifications.digest_send_time', '07:00');

        $this->settings->set('digest_send_time', '09:30');

        $this->assertSame('09:30', $this->settings->get('digest_send_time'));
    }

    #[Test]
    public function set_round_trips_scalar_string_value(): void
    {
        $this->settings->set('digest_send_time', '06:15');

        // A fresh repository instance proves the value persisted to the DB and
        // round-tripped through the model's array cast rather than living in
        // in-memory state.
        $this->assertSame('06:15', app(SettingsRepository::class)->get('digest_send_time'));
    }

    #[Test]
    public function set_round_trips_boolean_value(): void
    {
        $this->settings->set('digest_skip_empty', false);

        $this->assertFalse($this->settings->get('digest_skip_empty'));
    }

    #[Test]
    public function set_round_trips_integer_value(): void
    {
        $this->settings->set('a_tier_throttle_minutes', 120);

        $this->assertSame(120, $this->settings->get('a_tier_throttle_minutes'));
    }

    #[Test]
    public function set_round_trips_array_value(): void
    {
        $this->settings->set('enabled_sources', ['greenhouse', 'lever']);

        $this->assertSame(['greenhouse', 'lever'], $this->settings->get('enabled_sources'));
    }

    #[Test]
    public function set_upserts_a_single_row_per_key(): void
    {
        $this->settings->set('digest_send_time', '08:00');
        $this->settings->set('digest_send_time', '09:00');

        $this->assertSame(1, Setting::query()->where('key', 'digest_send_time')->count());
        $this->assertSame('09:00', $this->settings->get('digest_send_time'));
    }

    #[Test]
    public function set_many_writes_each_key(): void
    {
        $this->settings->setMany([
            'digest_send_time' => '10:00',
            'a_tier_throttle_minutes' => 30,
        ]);

        $this->assertSame('10:00', $this->settings->get('digest_send_time'));
        $this->assertSame(30, $this->settings->get('a_tier_throttle_minutes'));
    }

    #[Test]
    public function has_reflects_override_presence(): void
    {
        $this->assertFalse($this->settings->has('digest_send_time'));

        $this->settings->set('digest_send_time', '08:00');

        $this->assertTrue($this->settings->has('digest_send_time'));
    }

    #[Test]
    public function forget_reverts_to_config_default(): void
    {
        config()->set('notifications.digest_send_time', '07:00');
        $this->settings->set('digest_send_time', '11:11');

        $this->settings->forget('digest_send_time');

        $this->assertFalse($this->settings->has('digest_send_time'));
        $this->assertSame('07:00', $this->settings->get('digest_send_time'));
    }

    #[Test]
    public function all_layers_overrides_over_config_defaults(): void
    {
        config()->set('notifications.digest_send_time', '07:00');
        config()->set('notifications.a_tier_throttle_minutes', 60);

        $this->settings->set('a_tier_throttle_minutes', 90);

        $all = $this->settings->all();

        $this->assertSame('07:00', $all['digest_send_time']);
        $this->assertSame(90, $all['a_tier_throttle_minutes']);
    }

    #[Test]
    public function all_surfaces_unknown_override_keys(): void
    {
        $this->settings->set('custom_flag', true);

        $this->assertArrayHasKey('custom_flag', $this->settings->all());
        $this->assertTrue($this->settings->all()['custom_flag']);
    }

    #[Test]
    public function reads_are_scoped_to_the_active_tenant(): void
    {
        $this->settings->set('digest_send_time', '06:00');

        $otherTenant = Tenant::factory()->create();

        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            config()->set('notifications.digest_send_time', '07:00');

            // No row for the other tenant — falls back to config default.
            $this->assertSame('07:00', $this->settings->get('digest_send_time'));
            $this->assertFalse($this->settings->has('digest_send_time'));
        });

        // Original tenant still sees its own override.
        $this->assertSame('06:00', $this->settings->get('digest_send_time'));
    }

    #[Test]
    public function writes_are_scoped_to_the_active_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            $this->settings->set('digest_send_time', '23:00');
        });

        $this->assertSame(
            1,
            Setting::withoutTenantScope()
                ->where('tenant_id', $otherTenant->id)
                ->where('key', 'digest_send_time')
                ->count()
        );

        // The active (original) tenant has no such override.
        $this->assertFalse($this->settings->has('digest_send_time'));
    }
}
