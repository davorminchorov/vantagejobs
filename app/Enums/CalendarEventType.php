<?php

declare(strict_types=1);

namespace App\Enums;

enum CalendarEventType: string
{
    case INTERVIEW = 'interview';
    case DEADLINE = 'deadline';
    case FOLLOW_UP = 'follow_up';
    case REMINDER = 'reminder';
    case CUSTOM = 'custom';
}
