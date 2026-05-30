<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Typed accessor over the tenant-scoped key-value {@see Setting} model.
 *
 * Every later Phase-6 slice (digest scheduling, A-tier throttle, source-health
 * alerting, triage auto-dismiss, data retention) reads its runtime knobs
 * through this repository rather than touching `config()` directly. The
 * resolution order is:
 *
 *   1. A tenant-scoped `settings` row whose `key` matches (DB override).
 *   2. The matching `config(...)` default (see {@see CONFIG_PATHS}).
 *   3. The caller-supplied `$default`.
 *
 * Because step 2 always yields a sane value, a missing DB row never breaks
 * behavior — the store is purely an override layer on top of config defaults.
 *
 * The Setting model is tenant-scoped via BelongsToTenant, so every read and
 * write is implicitly scoped to the active tenant and the unique(tenant_id,
 * key) constraint guarantees one row per key.
 *
 * Octane-safe: this class holds no request or tenant state. Reads and writes
 * resolve the active tenant lazily through the Eloquent global tenant scope on
 * {@see Setting} (which reads {@see \App\Tenancy\TenantContext} at query time),
 * so a long-lived singleton never captures a stale tenant.
 */
final readonly class SettingsRepository
{
    /**
     * Maps a logical setting key to the `config()` dot-path that supplies its
     * default. Keys absent from this map fall back to `notifications.<key>`,
     * keeping the common case (the notification knobs) terse.
     *
     * @var array<string, string>
     */
    private const CONFIG_PATHS = [
        'digest_send_time' => 'notifications.digest_send_time',
        'timezone' => 'notifications.timezone',
        'dnd_start' => 'notifications.dnd_start',
        'dnd_end' => 'notifications.dnd_end',
        'digest_skip_empty' => 'notifications.digest_skip_empty',
        'a_tier_throttle_minutes' => 'notifications.a_tier_throttle_minutes',
        'weekly_summary_day' => 'notifications.weekly_summary_day',
        'a_tier_stale_days' => 'notifications.a_tier_stale_days',
        'consecutive_failure_alert' => 'sources.consecutive_failure_alert',
        'confidence_drop_wow_pct' => 'sources.confidence_drop_wow_pct',
        'auto_dismiss_threshold_days' => 'pipeline.follow_up_threshold_days',
        'artifact_retention_days' => 'scrapers.artifact_retention_days',
        'backup_retention_daily_days' => 'backup.retention.daily_days',
        'backup_retention_weekly_weeks' => 'backup.retention.weekly_weeks',
        'export_download_ttl_hours' => 'export.download_ttl_hours',
    ];

    public function __construct(private ConfigRepository $config) {}

    /**
     * Resolve a setting: DB override first, then config default, then the
     * caller-supplied fallback.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->where('key', $key)->first();

        if ($row !== null) {
            return $this->unwrap($row->getAttribute('value'));
        }

        return $this->config->get($this->configPathFor($key), $default);
    }

    /**
     * Upsert the value for a key within the current tenant. Scalars are wrapped
     * so they round-trip cleanly through the model's array cast on `value`.
     */
    public function set(string $key, mixed $value): void
    {
        $row = Setting::query()->where('key', $key)->first();

        if ($row === null) {
            $row = new Setting;
            $row->setAttribute('key', $key);
        }

        $row->setAttribute('value', $this->wrap($value));
        $row->save();
    }

    /**
     * Bulk upsert a map of key => value within the current tenant.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Remove a tenant-scoped override, reverting the key to its config default.
     */
    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
    }

    /**
     * Whether a tenant-scoped override row exists for the key (regardless of
     * its value).
     */
    public function has(string $key): bool
    {
        return Setting::query()->where('key', $key)->exists();
    }

    /**
     * Every known key resolved to its effective value (DB override layered over
     * config default). Backs the /settings UI which renders current values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $overrides = Setting::query()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (Setting $setting): array => [
                $setting->getAttribute('key') => $this->unwrap($setting->getAttribute('value')),
            ])
            ->all();

        $resolved = [];

        foreach (array_keys(self::CONFIG_PATHS) as $key) {
            $resolved[$key] = array_key_exists($key, $overrides)
                ? $overrides[$key]
                : $this->config->get(self::CONFIG_PATHS[$key]);
        }

        // Surface any override whose key is not in the known config map so the
        // UI never silently hides a stored value.
        return array_merge($resolved, $overrides);
    }

    private function configPathFor(string $key): string
    {
        return self::CONFIG_PATHS[$key] ?? 'notifications.'.$key;
    }

    /**
     * The model casts `value` to an array. Scalars are stored under a `value`
     * envelope so they survive the array cast; unwrap reverses that.
     */
    private function unwrap(mixed $stored): mixed
    {
        if (is_array($stored) && array_key_exists('value', $stored) && count($stored) === 1) {
            return $stored['value'];
        }

        return $stored;
    }

    /**
     * @return array<mixed>
     */
    private function wrap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return ['value' => $value];
    }
}
