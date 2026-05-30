<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AggregatorListingStatus;
use App\FollowUps\DueFollowUpsQuery;
use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assembles the payload for the daily digest email within the active tenant.
 *
 * The digest answers "what changed and what needs my attention today" with
 * four sections:
 *  - New activity: unseen Event rows in the trailing 24h, grouped by company.
 *  - Discover queue: count of AggregatorListing rows still awaiting triage
 *    (status NEW / OVERFLOW), the same backlog the /discover page shows.
 *  - Stale A-tier: A-tier companies whose last_checked_at is older than the
 *    configured stale threshold (or never checked), so high-priority targets
 *    don't silently rot.
 *  - Follow-up queue: count of contacts due for a follow-up, reusing the
 *    shared DueFollowUpsQuery definition so the digest never drifts from the
 *    /follow-ups page.
 *
 * All reads run through the Eloquent global tenant scope, so callers never
 * pass a tenant id; the active TenantContext bounds every query.
 */
final readonly class DailyDigestQuery
{
    /**
     * Discover-queue statuses that still need a human decision. Mirrors the
     * /discover page's "pending" definition; kept local so this slice owns it.
     *
     * @var array<int, AggregatorListingStatus>
     */
    private const PENDING_DISCOVER_STATUSES = [
        AggregatorListingStatus::NEW,
        AggregatorListingStatus::OVERFLOW,
    ];

    public function __construct(
        private ConfigRepository $config,
        private DueFollowUpsQuery $dueFollowUps,
    ) {}

    /**
     * Build the full digest payload as of $now.
     */
    public function build(?CarbonImmutable $now = null): DailyDigest
    {
        $now ??= CarbonImmutable::now();
        $windowStart = $now->subDay();

        return new DailyDigest(
            companyActivity: $this->companyActivity($windowStart),
            discoverQueueCount: $this->discoverQueueCount(),
            staleATierCompanies: $this->staleATierCompanies($now),
            followUpQueueCount: $this->dueFollowUps->count(),
        );
    }

    /**
     * Unseen events in the trailing 24h, grouped by company, newest first
     * within each group. Eager-loads the owning company so rendering never
     * lazy-loads. Companies with no activity in the window are omitted.
     *
     * @return array<int, DailyDigestCompanyActivity>
     */
    private function companyActivity(CarbonImmutable $windowStart): array
    {
        /** @var Collection<int, Event> $events */
        $events = Event::query()
            ->whereNotNull('company_id')
            ->where('occurred_at', '>=', $windowStart)
            ->with(['company' => fn ($query) => $query->select(['id', 'name', 'tier'])])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $groups = [];

        foreach ($events as $event) {
            $company = $event->company;

            if (! $company instanceof Company) {
                continue;
            }

            $groups[$company->id] ??= new DailyDigestCompanyActivity(
                companyId: $company->id,
                companyName: (string) $company->name,
                tier: (string) $company->tier,
                events: [],
            );

            $groups[$company->id]->events[] = $event;
        }

        return array_values($groups);
    }

    /**
     * Number of discover-queue listings still awaiting triage.
     */
    private function discoverQueueCount(): int
    {
        return AggregatorListing::query()
            ->whereIn('status', array_map(
                static fn (AggregatorListingStatus $status): string => $status->value,
                self::PENDING_DISCOVER_STATUSES,
            ))
            ->count();
    }

    /**
     * A-tier companies that have gone past the stale threshold without a
     * check, oldest-checked first (never-checked sort to the top).
     *
     * @return Collection<int, Company>
     */
    private function staleATierCompanies(CarbonImmutable $now): Collection
    {
        $cutoff = $now->subDays($this->staleDays());

        return Company::query()
            ->where('tier', 'A')
            ->where('is_active', true)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $cutoff);
            })
            ->select(['id', 'name', 'tier', 'last_checked_at'])
            ->orderByRaw('last_checked_at is null desc')
            ->orderBy('last_checked_at')
            ->orderBy('name')
            ->get();
    }

    private function staleDays(): int
    {
        return (int) $this->config->get('notifications.a_tier_stale_days', 7);
    }
}
