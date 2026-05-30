<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineStatus: string
{
    case RESEARCHING = 'researching';
    case APPLIED = 'applied';
    case SCREEN = 'screen';
    case ONSITE = 'onsite';
    case OFFER = 'offer';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';
}
