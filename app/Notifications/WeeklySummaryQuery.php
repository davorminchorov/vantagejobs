<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EventType;
use App\FollowUps\DueFollowUpsQuery;
use App\Models\Company;
use App\Models\Event;
use App\Enums\PipelineStatus;
use App\Models\Offer;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Computes the Sunday-morning weekly rollup for the active tenant: 7-day counts
 * of new roles, companies added, applications sent, and follow-ups due; the top
 * signals of the week ranked by EventType priority; and a pipeline-stage trend
 * comparing the current week to the prior week.
 *
 * Every read runs through the Eloquent global tenant scope, so callers never
 * pass a tenant id; the active TenantContext bounds every query. The window is
 * the rolling 7 days ending at the supplied "now"; the prior window is the 7
 * days before that, used only for the pipeline trend delta.
 *
 * @phpstan-type SignalRow array{type: string, label: string, title: string, company: ?string, occurred_at: string}
 * @phpstan-type TrendRow array{status: string, label: string, current: int, prior: int, delta: int}
 */
final readonly class WeeklySummaryQuery
{
    /**
     * EventType priority for ranking the week's top signals. Lower number wins.
     * Funding and layoffs are the loudest market signals, then a fresh role.
     *
     * @var array<string, int>
     */
    private const SIGNAL_PRIORITY = [
        'funding' => 1,
        'layoff' => 2,
        'new_role' => 3,
        'blog_post' => 4,
        'ghost_detected' => 5,
    ];

    /**
     * Maximum number of top signals carried into the email body.
     */
    private const TOP_SIGNALS_LIMIT = 5;

    public function __construct(private DueFollowUpsQuery $dueFollowUps) {}

    /**
     * Build the full summary payload for the week ending at $now.
     *
     * @return array{
     *     window_start: CarbonImmutable,
     *     window_end: CarbonImmutable,
     *     new_roles: int,
     *     companies_added: int,
     *     applications_sent: int,
     *     offers_recorded: int,
     *     follow_ups_due: int,
     *     top_signals: array<int, array{type: string, label: string, title: string, company: ?string, occurred_at: string}>,
     *     pipeline_trend: array<int, array{status: string, label: string, current: int, prior: int, delta: int}>,
     *     has_activity: bool
     * }
     */
    public function forWeekEnding(CarbonImmutable $now): array
    {
        $windowStart = $now->subDays(7);
        $priorStart = $now->subDays(14);

        $newRoles = $this->newRolesCount($windowStart, $now);
        $companiesAdded = $this->companiesAddedCount($windowStart, $now);
        $applicationsSent = $this->applicationsSentCount($windowStart, $now);
        $offersRecorded = $this->offersRecordedCount($windowStart, $now);
        $followUpsDue = $this->dueFollowUps->count();
        $topSignals = $this->topSignals($windowStart, $now);
        $pipelineTrend = $this->pipelineTrend($windowStart, $now, $priorStart);

        $hasActivity = $newRoles > 0
            || $companiesAdded > 0
            || $applicationsSent > 0
            || $offersRecorded > 0
            || $followUpsDue > 0
            || $topSignals !== [];

        return [
            'window_start' => $windowStart,
            'window_end' => $now,
            'new_roles' => $newRoles,
            'companies_added' => $companiesAdded,
            'applications_sent' => $applicationsSent,
            'offers_recorded' => $offersRecorded,
            'follow_ups_due' => $followUpsDue,
            'top_signals' => $topSignals,
            'pipeline_trend' => $pipelineTrend,
            'has_activity' => $hasActivity,
        ];
    }

    private function newRolesCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Role::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    private function companiesAddedCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Company::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * Applications sent: roles whose applied_at falls inside the window. A role
     * is "applied" when the user records an applied_at, independent of when the
     * role was first ingested.
     */
    private function applicationsSentCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Role::query()
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', [$start, $end])
            ->count();
    }

    private function offersRecordedCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Offer::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * The week's loudest signals, ranked by EventType priority then recency,
     * capped at TOP_SIGNALS_LIMIT. Only the priority-mapped event types are
     * considered; everything else is noise for the weekly headline.
     *
     * @return array<int, array{type: string, label: string, title: string, company: ?string, occurred_at: string}>
     */
    private function topSignals(CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var Collection<int, Event> $events */
        $events = Event::query()
            ->whereIn('type', array_keys(self::SIGNAL_PRIORITY))
            ->whereBetween('occurred_at', [$start, $end])
            ->with(['company' => fn ($query) => $query->select(['id', 'name'])])
            ->get();

        return $events
            ->sort(function (Event $first, Event $second): int {
                $firstPriority = self::SIGNAL_PRIORITY[$first->getAttribute('type')] ?? PHP_INT_MAX;
                $secondPriority = self::SIGNAL_PRIORITY[$second->getAttribute('type')] ?? PHP_INT_MAX;

                if ($firstPriority !== $secondPriority) {
                    return $firstPriority <=> $secondPriority;
                }

                return $second->occurred_at <=> $first->occurred_at;
            })
            ->take(self::TOP_SIGNALS_LIMIT)
            ->map(function (Event $event): array {
                $type = (string) $event->getAttribute('type');

                return [
                    'type' => $type,
                    'label' => $this->signalLabel($type),
                    'title' => (string) ($event->getAttribute('title') ?? ''),
                    'company' => $event->company?->getAttribute('name'),
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Pipeline-stage trend: count of roles in each pipeline status whose state
     * was recorded this week versus the prior week, by created_at as a proxy
     * for "entered the pipeline". Surfaces movement direction per stage.
     *
     * @return array<int, array{status: string, label: string, current: int, prior: int, delta: int}>
     */
    private function pipelineTrend(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $priorStart,
    ): array {
        $current = $this->pipelineCountsForWindow($start, $end);
        $prior = $this->pipelineCountsForWindow($priorStart, $start);

        $rows = [];

        foreach (PipelineStatus::cases() as $status) {
            $currentCount = $current[$status->value] ?? 0;
            $priorCount = $prior[$status->value] ?? 0;

            if ($currentCount === 0 && $priorCount === 0) {
                continue;
            }

            $rows[] = [
                'status' => $status->value,
                'label' => $this->pipelineLabel($status),
                'current' => $currentCount,
                'prior' => $priorCount,
                'delta' => $currentCount - $priorCount,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function pipelineCountsForWindow(CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var array<string, int> $counts */
        $counts = Role::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('pipeline_status, count(*) as aggregate')
            ->groupBy('pipeline_status')
            ->pluck('aggregate', 'pipeline_status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        return $counts;
    }

    private function signalLabel(string $type): string
    {
        $event = EventType::tryFrom($type);

        return match ($event) {
            EventType::FUNDING => 'Funding',
            EventType::LAYOFF => 'Layoff',
            EventType::NEW_ROLE => 'New role',
            EventType::BLOG_POST => 'Blog post',
            EventType::GHOST_DETECTED => 'Ghost detected',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function pipelineLabel(PipelineStatus $status): string
    {
        return match ($status) {
            PipelineStatus::RESEARCHING => 'Researching',
            PipelineStatus::APPLIED => 'Applied',
            PipelineStatus::SCREEN => 'Screen',
            PipelineStatus::ONSITE => 'Onsite',
            PipelineStatus::OFFER => 'Offer',
            PipelineStatus::REJECTED => 'Rejected',
            PipelineStatus::WITHDRAWN => 'Withdrawn',
        };
    }
}
