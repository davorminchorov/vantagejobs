<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Canonical Setting keys used by the /settings runtime-config surface.
 *
 * Keeping the literal strings in one place keeps controllers, the
 * SettingsRepository, and tests aligned and avoids typos drifting a stored row
 * out of reach of its reader.
 */
final class SettingKey
{
    /*
    | Notifications
    */
    public const string DIGEST_SEND_TIME = 'digest_send_time';

    public const string DND_START = 'dnd_start';

    public const string DND_END = 'dnd_end';

    public const string DIGEST_SKIP_EMPTY = 'digest_skip_empty';

    public const string NOTIFICATION_EMAIL = 'notification_email';

    public const string WEEKLY_SUMMARY_ENABLED = 'weekly_summary_enabled';

    /*
    | Triage
    */
    public const string AUTO_DISMISS_THRESHOLD = 'auto_dismiss_threshold';

    public const string DISMISSED_RETENTION_DAYS = 'dismissed_retention_days';
}
