<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkplaceType: string
{
    case UNSPECIFIED = 'unspecified';
    case ON_SITE = 'on_site';
    case REMOTE = 'remote';
    case HYBRID = 'hybrid';
}
