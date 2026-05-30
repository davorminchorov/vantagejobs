<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the Notifications settings sub-page. Current values are read from the
 * SettingsRepository, falling back to config('notifications.*') defaults so a
 * tenant that has never saved still sees the shipped configuration. The
 * notification recipient defaults to the signed-in user's email when unset.
 */
final readonly class NotificationsController
{
    public function __construct(
        private SettingsRepository $settings,
        private ConfigRepository $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Notifications', [
            'settings' => [
                'digest_send_time' => (string) $this->settings->get(
                    SettingKey::DIGEST_SEND_TIME,
                    $this->config->get('notifications.digest_send_time'),
                ),
                'dnd_start' => (string) $this->settings->get(
                    SettingKey::DND_START,
                    $this->config->get('notifications.dnd_start'),
                ),
                'dnd_end' => (string) $this->settings->get(
                    SettingKey::DND_END,
                    $this->config->get('notifications.dnd_end'),
                ),
                'digest_skip_empty' => (bool) $this->settings->get(
                    SettingKey::DIGEST_SKIP_EMPTY,
                    $this->config->get('notifications.digest_skip_empty'),
                ),
                'notification_email' => (string) $this->settings->get(
                    SettingKey::NOTIFICATION_EMAIL,
                    $user?->getAttribute('email') ?? '',
                ),
                'weekly_summary_enabled' => (bool) $this->settings->get(
                    SettingKey::WEEKLY_SUMMARY_ENABLED,
                    true,
                ),
            ],
        ]);
    }
}
