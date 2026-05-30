<?php

declare(strict_types=1);

namespace App\Backups;

use App\Models\Setting;
use App\Models\User;
use App\Settings\SettingKey;

/**
 * Resolves the operator email a backup failure alert is sent to.
 *
 * Backups are a platform-wide concern, not tenant-scoped, so this resolver
 * runs outside any tenant context. It mirrors the source-health recipient
 * logic: the notification_email Setting wins, falling back to the first user's
 * login email. Returns null when neither is available (alert is then skipped).
 */
final readonly class BackupAlertRecipient
{
    public function resolve(): ?string
    {
        $configured = Setting::withoutTenantScope()
            ->where('key', SettingKey::NOTIFICATION_EMAIL)
            ->value('value');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $email = User::withoutTenantScope()->orderBy('id')->value('email');

        return is_string($email) && $email !== '' ? $email : null;
    }
}
