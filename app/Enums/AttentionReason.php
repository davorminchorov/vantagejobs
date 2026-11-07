<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an application needs attention. NextStepOverdue wins when both apply.
 */
enum AttentionReason: string
{
    case NextStepOverdue = 'next_step_overdue';
    case Quiet = 'quiet';

    /**
     * @param  int|null  $daysQuiet  Whole days since the last activity, when known.
     */
    public function describe(?int $daysQuiet = null): string
    {
        return match ($this) {
            self::NextStepOverdue => 'Next step overdue',
            self::Quiet => $daysQuiet === null
                ? 'No activity yet'
                : "No activity for {$daysQuiet} ".($daysQuiet === 1 ? 'day' : 'days'),
        };
    }
}
