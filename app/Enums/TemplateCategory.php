<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateCategory: string
{
    case COLD_INTRO = 'cold_intro';
    case REFERRAL_ASK = 'referral_ask';
    case FOLLOW_UP = 'follow_up';
    case POST_INTERVIEW = 'post_interview';
    case DECLINE = 'decline';
    case CUSTOM = 'custom';
}
