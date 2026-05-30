<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\Company;
use App\Models\NotificationLogEntry;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Selects A-tier companies that have one or more newly-discovered open Roles
 * eligible for an instant alert, grouping the new roles under their company so
 * the sweep can send a single email per company.
 *
 * A company is eligible when all hold:
 *  - company.tier = 'A', AND
 *  - it has at least one Role created within the lookback window (roles that
 *    first appeared since the previous sweep), AND
 *  - no A_TIER_ALERT notification_log row exists for that company inside the
 *    throttle window (config notifications.a_tier_throttle_minutes, default 60).
 *
 * The throttle predicate reads the append-only notification_log ledger, so the
 * sweep stays idempotent: once an alert is recorded for a company, the same
 * company is skipped until the throttle window elapses, regardless of how many
 * times the sweep runs in between.
 *
 * All reads run through the Eloquent global tenant scope, so callers never pass
 * a tenant id; the active TenantContext bounds every query.
 */
final readonly class ATierAlertsQuery
{
    /**
     * Hydrate the eligible A-tier companies, each with its window of newly
     * created open roles eager-loaded onto the `roles` relation.
     *
     * @return Collection<int, Company>
     */
    public function get(
        CarbonImmutable $now,
        int $lookbackMinutes,
        int $throttleMinutes,
    ): Collection {
        $windowStart = $now->subMinutes($lookbackMinutes);
        $throttleStart = $now->subMinutes($throttleMinutes);

        $throttledCompanyIds = $this->throttledCompanyIds($throttleStart);

        return Company::query()
            ->where('tier', 'A')
            ->whereNotIn('id', $throttledCompanyIds)
            ->whereHas('roles', function ($query) use ($windowStart, $now): void {
                $query->where('is_open', true)
                    ->whereBetween('created_at', [$windowStart, $now]);
            })
            ->with(['roles' => function ($query) use ($windowStart, $now): void {
                $query->where('is_open', true)
                    ->whereBetween('created_at', [$windowStart, $now])
                    ->orderByDesc('created_at')
                    ->orderBy('id');
            }])
            ->orderBy('id')
            ->get();
    }

    /**
     * Company ids that already received an A-tier alert inside the throttle
     * window and must be skipped on this sweep.
     *
     * @return array<int, int>
     */
    private function throttledCompanyIds(CarbonImmutable $throttleStart): array
    {
        return NotificationLogEntry::query()
            ->where('kind', NotificationKind::A_TIER_ALERT)
            ->whereNotNull('company_id')
            ->where('sent_at', '>=', $throttleStart)
            ->pluck('company_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
