<?php

declare(strict_types=1);

namespace App\Enums;

enum ApplicationSource: string
{
    case COLD = 'cold';
    case REFERRAL = 'referral';
    case RECRUITER = 'recruiter';
}
