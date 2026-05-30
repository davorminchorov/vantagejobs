<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationKind: string
{
    case DAILY_DIGEST = 'daily_digest';
    case A_TIER_ALERT = 'a_tier_alert';
    case WEEKLY_SUMMARY = 'weekly_summary';
    case SOURCE_HEALTH = 'source_health';
}
