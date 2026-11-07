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

    public function label(): string
    {
        return match ($this) {
            self::RESEARCHING => 'Researching',
            self::APPLIED => 'Applied',
            self::SCREEN => 'Screen',
            self::ONSITE => 'Onsite',
            self::OFFER => 'Offer',
            self::REJECTED => 'Rejected',
            self::WITHDRAWN => 'Withdrawn',
        };
    }

    /**
     * Closed applications: they never need attention and are not counted as active.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::REJECTED, self::WITHDRAWN => true,
            default => false,
        };
    }
}
