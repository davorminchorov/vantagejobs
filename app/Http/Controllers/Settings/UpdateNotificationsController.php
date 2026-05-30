<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\UpdateNotificationsRequest;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;

/**
 * Persists the Notifications settings sub-page. Validated values are written
 * to the tenant-scoped Setting rows via the SettingsRepository, layering over
 * the config defaults consumed by the digest, A-tier alert, and weekly summary
 * schedulers.
 */
final readonly class UpdateNotificationsController
{
    public function __construct(
        private SettingsRepository $settings,
    ) {}

    public function __invoke(UpdateNotificationsRequest $request): RedirectResponse
    {
        $this->settings->setMany([
            SettingKey::DIGEST_SEND_TIME => $request->string('digest_send_time')->value(),
            SettingKey::DND_START => $request->string('dnd_start')->value(),
            SettingKey::DND_END => $request->string('dnd_end')->value(),
            SettingKey::DIGEST_SKIP_EMPTY => $request->boolean('digest_skip_empty'),
            SettingKey::NOTIFICATION_EMAIL => $request->string('notification_email')->value(),
            SettingKey::WEEKLY_SUMMARY_ENABLED => $request->boolean('weekly_summary_enabled'),
        ]);

        return redirect()
            ->route('settings.notifications')
            ->with('success', 'Notification settings saved.');
    }
}
