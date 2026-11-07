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
    case WORKDAY = 'workday';

    /**
     * Brand-cased display name, e.g. "SmartRecruiters".
     */
    public function label(): string
    {
        return match ($this) {
            self::GREENHOUSE => 'Greenhouse',
            self::LEVER => 'Lever',
            self::ASHBY => 'Ashby',
            self::WORKABLE => 'Workable',
            self::SMARTRECRUITERS => 'SmartRecruiters',
            self::PERSONIO => 'Personio',
            self::RECRUITEE => 'Recruitee',
            self::TEAMTAILOR => 'Teamtailor',
            self::PINPOINT => 'Pinpoint',
            self::BAMBOOHR => 'BambooHR',
            self::RIPPLING => 'Rippling',
            self::GEM => 'Gem',
            self::WORKDAY => 'Workday',
        };
    }
}
