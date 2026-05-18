<?php

declare(strict_types=1);

namespace App\Enums;

enum AtsType: string
{
    case GREENHOUSE = 'greenhouse';
    case LEVER = 'lever';
    case ASHBY = 'ashby';
}
