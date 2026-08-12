<?php

declare(strict_types=1);

namespace App\Enums;

enum AtsType: string
{
    case GREENHOUSE = 'greenhouse';
    case LEVER = 'lever';
    case ASHBY = 'ashby';
    case WORKABLE = 'workable';
    case SMARTRECRUITERS = 'smartrecruiters';
    case PERSONIO = 'personio';
    case RECRUITEE = 'recruitee';
    case TEAMTAILOR = 'teamtailor';
    case PINPOINT = 'pinpoint';
    case BAMBOOHR = 'bamboohr';
    case RIPPLING = 'rippling';
    case GEM = 'gem';
}
