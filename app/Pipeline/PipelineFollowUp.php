<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Event;
use App\Models\Role;
use App\Models\RoleStageNote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Decides whether a pipeline Role should be flagged as needing a follow-up.
 *
 * A role is "stale" when it sits in an active waiting stage (APPLIED or SCREEN)
 * and has had no recorded activity within the configured threshold. Activity is
 * the most recent of: applied_at, a next_action_date that has come due, the
 * latest per-stage note, or the latest ROLE_STATUS_CHANGED event on the role.
 */
final readonly class PipelineFollowUp
{
    /**
     * Stages where the user is waiting on a counterparty and a lull means a
     * nudge is overdue. Other stages (OFFER, ONSITE, terminal states) are
     * intentionally excluded from the card flag.
     *
     * @var array<int, PipelineStatus>
     */
    private const FLAGGABLE_STAGES = [
        PipelineStatus::APPLIED,
        PipelineStatus::SCREEN,
    ];

    public function __construct(private ConfigRepository $config) {}

    /**
     * Compute the follow-up flag for a set of roles in one pass.
     *
     * Pre-fetches the latest stage-note and status-change timestamps for the
     * given role ids so the board render stays a small fixed number of queries
     * regardless of column size.
     *
     * @param  iterable<Role>  $roles
     * @return array<int, bool>  keyed by role id
     */
    public function flagsFor(iterable $roles): array
    {
        $roleIds = [];
        $rolesById = [];
        foreach ($roles as $role) {
            $roleIds[] = $role->id;
            $rolesById[$role->id] = $role;
        }

        if ($roleIds === []) {
            return [];
        }

        $now = CarbonImmutable::now();
        $threshold = $now->subDays($this->thresholdDays());

        $latestNoteAt = $this->latestStageNoteTimestamps($roleIds);
        $latestStatusChangeAt = $this->latestStatusChangeTimestamps($roleIds);

        $flags = [];
        foreach ($rolesById as $id => $role) {
            $flags[$id] = $this->isStale(
                $role,
                $latestNoteAt[$id] ?? null,
                $latestStatusChangeAt[$id] ?? null,
                $now,
                $threshold,
            );
        }

        return $flags;
    }

    public function thresholdDays(): int
    {
        return (int) $this->config->get('pipeline.follow_up_threshold_days', 7);
    }

    private function isStale(
        Role $role,
        ?CarbonImmutable $latestNoteAt,
        ?CarbonImmutable $latestStatusChangeAt,
        CarbonImmutable $now,
        CarbonImmutable $threshold,
    ): bool {
        if (! in_array($role->pipeline_status, self::FLAGGABLE_STAGES, true)) {
            return false;
        }

        $lastActivity = $this->lastActivityAt($role, $latestNoteAt, $latestStatusChangeAt, $now);

        // No recorded activity at all on an active application is itself stale.
        if ($lastActivity === null) {
            return true;
        }

        return $lastActivity->lessThan($threshold);
    }

    private function lastActivityAt(
        Role $role,
        ?CarbonImmutable $latestNoteAt,
        ?CarbonImmutable $latestStatusChangeAt,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        $candidates = [];

        if ($role->applied_at !== null) {
            $candidates[] = CarbonImmutable::parse($role->applied_at);
        }

        // A next action only counts as activity once its date has passed; a
        // future action is a plan, not a touch.
        if ($role->next_action_date !== null) {
            $actionDate = CarbonImmutable::parse($role->next_action_date)->endOfDay();
            if ($actionDate->lessThanOrEqualTo($now)) {
                $candidates[] = $actionDate;
            }
        }

        if ($latestNoteAt !== null) {
            $candidates[] = $latestNoteAt;
        }

        if ($latestStatusChangeAt !== null) {
            $candidates[] = $latestStatusChangeAt;
        }

        if ($candidates === []) {
            return null;
        }

        return array_reduce(
            $candidates,
            static fn (?CarbonImmutable $carry, CarbonImmutable $item): CarbonImmutable => $carry === null || $item->greaterThan($carry) ? $item : $carry,
        );
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<int, CarbonImmutable>  keyed by role id
     */
    private function latestStageNoteTimestamps(array $roleIds): array
    {
        return RoleStageNote::query()
            ->whereIn('role_id', $roleIds)
            ->selectRaw('role_id, max(created_at) as latest_at')
            ->groupBy('role_id')
            ->get()
            ->mapWithKeys(static fn (RoleStageNote $note): array => [
                (int) $note->getAttribute('role_id') => CarbonImmutable::parse((string) $note->getAttribute('latest_at')),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<int, CarbonImmutable>  keyed by role id
     */
    private function latestStatusChangeTimestamps(array $roleIds): array
    {
        return Event::query()
            ->whereIn('role_id', $roleIds)
            ->where('type', EventType::ROLE_STATUS_CHANGED->value)
            ->selectRaw('role_id, max(occurred_at) as latest_at')
            ->groupBy('role_id')
            ->get()
            ->mapWithKeys(static fn (Event $event): array => [
                (int) $event->getAttribute('role_id') => CarbonImmutable::parse((string) $event->getAttribute('latest_at')),
            ])
            ->all();
    }
}
