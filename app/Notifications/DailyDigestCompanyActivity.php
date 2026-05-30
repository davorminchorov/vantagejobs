<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Event;

/**
 * One company's bucket of unseen events for the daily digest. Mutable only
 * during assembly in DailyDigestQuery (events are appended as rows are
 * grouped); treated as read-only once the DailyDigest is built.
 */
final class DailyDigestCompanyActivity
{
    /**
     * @param array<int, Event> $events
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly string $tier,
        public array $events,
    ) {}
}
