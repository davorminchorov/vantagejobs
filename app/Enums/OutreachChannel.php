<?php

declare(strict_types=1);

namespace App\Enums;

enum OutreachChannel: string
{
    case EMAIL = 'email';
    case LINKEDIN = 'linkedin';
    case PHONE = 'phone';
    case OTHER = 'other';
}
