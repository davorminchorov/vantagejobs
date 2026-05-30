<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;

/**
 * Immutable payload for one tenant's daily digest, produced by
 * DailyDigestQuery and consumed by DailyDigestMail.
 *
 * @param array<int, DailyDigestCompanyActivity> $companyActivity
 * @param Collection<int, Company>               $staleATierCompanies
 */
final readonly class DailyDigest
{
    /**
     * @param array<int, DailyDigestCompanyActivity> $companyActivity
     * @param Collection<int, Company>               $staleATierCompanies
     */
    public function __construct(
        public array $companyActivity,
        public int $discoverQueueCount,
        public Collection $staleATierCompanies,
        public int $followUpQueueCount,
    ) {}

    /**
     * Total number of unseen events across every company group.
     */
    public function eventCount(): int
    {
        return array_sum(array_map(
            static fn (DailyDigestCompanyActivity $activity): int => count($activity->events),
            $this->companyActivity,
        ));
    }

    /**
     * True when nothing in the window warrants a send: no new events, an empty
     * discover queue, no stale A-tier targets, and no due follow-ups. The
     * digest-skip-empty Setting gates the send on this.
     */
    public function isEmpty(): bool
    {
        return $this->eventCount() === 0
            && $this->discoverQueueCount === 0
            && $this->staleATierCompanies->isEmpty()
            && $this->followUpQueueCount === 0;
    }
}
