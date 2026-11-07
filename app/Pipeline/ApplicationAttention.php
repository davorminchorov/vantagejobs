<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Enums\AttentionReason;
use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\Event;
use App\Models\Role;
use App\Models\RoleStageNote;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The single "needs attention" signal for an application (a Role with a
 * pipeline_status). An application needs attention when either holds:
 *
 *  - NextStepOverdue: next_action_date is before today and the stage is not
 *    terminal;
 *  - Quiet: the stage is applied or screen and the last activity is older than
 *    the per-tenant follow-up threshold (or there is no activity at all).
 *
 * NextStepOverdue wins when both apply. Last activity is the latest of
 * applied_at, a next_action_date that has passed, the latest stage note and
 * the latest ROLE_STATUS_CHANGED event.
 *
 * Reads run through the Eloquent global tenant scope, so the active
 * TenantContext bounds every query, including the threshold setting.
 */
final readonly class ApplicationAttention
{
    /**
     * Stages where the user waits on the other side, so a lull means a nudge is due.
     *
     * @var array<int, PipelineStatus>
     */
    private const WAITING_STAGES = [
        PipelineStatus::APPLIED,
        PipelineStatus::SCREEN,
    ];

    public function __construct(private SettingsRepository $settings) {}

    public function thresholdDays(): int
    {
        $days = (int) $this->settings->get(SettingKey::FOLLOW_UP_THRESHOLD_DAYS, 7);

        return $days > 0 ? $days : 7;
    }

    /**
     * Assess a set of roles with a fixed number of queries.
     *
     * @param  iterable<Role>  $roles
     * @return array<int, array{attention: AttentionReason|null, last_activity_at: CarbonImmutable|null, days_quiet: int|null}>  keyed by role id
     */
    public function assess(iterable $roles): array
    {
        $rolesById = [];
        foreach ($roles as $role) {
            $rolesById[$role->id] = $role;
        }

        if ($rolesById === []) {
            return [];
        }

        $roleIds = array_keys($rolesById);
        $latestNoteAt = $this->latestStageNoteTimestamps($roleIds);
        $latestStatusChangeAt = $this->latestStatusChangeTimestamps($roleIds);

        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $threshold = $now->subDays($this->thresholdDays());

        $result = [];
        foreach ($rolesById as $id => $role) {
            $lastActivity = $this->lastActivityAt(
                $role,
                $latestNoteAt[$id] ?? null,
                $latestStatusChangeAt[$id] ?? null,
                $now,
            );

            $result[$id] = [
                'attention' => $this->reason($role, $lastActivity, $today, $threshold),
                'last_activity_at' => $lastActivity,
                'days_quiet' => $lastActivity === null
                    ? null
                    : max(0, (int) $lastActivity->startOfDay()->diffInDays($today)),
            ];
        }

        return $result;
    }

    /**
     * Every application that needs attention, most urgent first: overdue next
     * steps before quiet ones, then longest quiet first.
     *
     * @return Collection<int, AttentionItem>
     */
    public function needingAttention(): Collection
    {
        $roles = Role::query()
            ->whereNotNull('pipeline_status')
            ->with('company:id,name')
            ->get();

        $assessments = $this->assess($roles);

        return $roles
            ->map(static fn (Role $role): ?AttentionItem => ($assessments[$role->id]['attention'] ?? null) === null
                ? null
                : new AttentionItem(
                    roleId: $role->id,
                    title: (string) $role->title,
                    companyName: $role->company?->name,
                    reason: $assessments[$role->id]['attention'],
                    daysQuiet: $assessments[$role->id]['days_quiet'],
                ))
            ->filter()
            ->sortBy([
                static fn (AttentionItem $a, AttentionItem $b): int => ($a->reason === AttentionReason::NextStepOverdue ? 0 : 1) <=> ($b->reason === AttentionReason::NextStepOverdue ? 0 : 1),
                static fn (AttentionItem $a, AttentionItem $b): int => ($b->daysQuiet ?? PHP_INT_MAX) <=> ($a->daysQuiet ?? PHP_INT_MAX),
                static fn (AttentionItem $a, AttentionItem $b): int => $a->roleId <=> $b->roleId,
            ])
            ->values();
    }

    private function reason(Role $role, ?CarbonImmutable $lastActivity, CarbonImmutable $today, CarbonImmutable $threshold): ?AttentionReason
    {
        $status = $role->pipeline_status;

        if ($status === null || $status->isTerminal()) {
            return null;
        }

        if ($role->next_action_date !== null && $role->next_action_date->startOfDay()->lessThan($today)) {
            return AttentionReason::NextStepOverdue;
        }

        if (in_array($status, self::WAITING_STAGES, true)
            && ($lastActivity === null || $lastActivity->lessThan($threshold))) {
            return AttentionReason::Quiet;
        }

        return null;
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
