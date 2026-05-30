<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Enums\AggregatorListingStatus;
use App\Enums\BulkAction;
use App\Models\AggregatorListing;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Applies a bulk action to a set of rows inside a single transaction and
 * records exactly one AuditLog row capturing the prior state of each touched
 * row. The captured before-state is the sole input the undo controller needs
 * to reverse the action within the soft-undo window.
 *
 * Reversible (action, entity) pairs:
 *   CHANGE_TIER  company                      -> tier
 *   TAG / UNTAG  company | role               -> taggables pivot
 *   DISMISS      company                      -> status = 'graveyard'
 *                aggregator_listing           -> status = 'dismissed'
 *   BOOKMARK     role | aggregator_listing    -> bookmarks row
 *   MARK_CLOSED  role                         -> is_open = false
 *
 * Capture shape (AuditLog.context):
 *   {
 *     action: string, entity: string, ids: int[], params: array,
 *     before: [{ id: int, field: string, old: mixed }, ...]
 *   }
 */
final readonly class BulkActionExecutor
{
    public const ENTITY_COMPANY = 'company';

    public const ENTITY_ROLE = 'role';

    public const ENTITY_LISTING = 'aggregator_listing';

    public const BOOKMARK_DEFAULT_TTL_DAYS = 30;

    public const ACTION = 'bulk.perform';

    public const REVERSAL_ACTION = 'bulk.undo';

    public function __construct(
        private ConnectionResolverInterface $db,
        private TenantContext $tenant,
    ) {}

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $params
     */
    public function perform(BulkAction $action, string $entity, array $ids, array $params, User $actor): AuditLog
    {
        return $this->db->connection()->transaction(function () use ($action, $entity, $ids, $params, $actor): AuditLog {
            $rows = $this->loadRows($entity, $ids);

            $before = match ($action) {
                BulkAction::CHANGE_TIER => $this->applyChangeTier($rows, $params),
                BulkAction::TAG => $this->applyTag($rows, $entity, $params),
                BulkAction::UNTAG => $this->applyUntag($rows, $entity, $params),
                BulkAction::DISMISS => $this->applyDismiss($entity, $rows),
                BulkAction::BOOKMARK => $this->applyBookmark($entity, $rows),
                BulkAction::MARK_CLOSED => $this->applyMarkClosed($rows),
            };

            return $this->writeAudit(self::ACTION, $action, $entity, $params, $before, $actor);
        });
    }

    /**
     * Reverse a previously-recorded bulk action using only its captured
     * before-state. Returns the reversal AuditLog row.
     */
    public function undo(AuditLog $log, User $actor): AuditLog
    {
        /** @var array<string, mixed> $context */
        $context = $log->context ?? [];
        $entity = (string) ($context['entity'] ?? '');
        $action = BulkAction::from((string) ($context['action'] ?? ''));
        /** @var array<int, array{id:int, field:string, old:mixed}> $before */
        $before = $context['before'] ?? [];

        return $this->db->connection()->transaction(function () use ($action, $entity, $before, $log, $actor): AuditLog {
            match ($action) {
                BulkAction::CHANGE_TIER => $this->revertColumn($entity, $before, 'tier'),
                BulkAction::DISMISS => $this->revertColumn($entity, $before, 'status'),
                BulkAction::MARK_CLOSED => $this->revertColumn($entity, $before, 'is_open'),
                BulkAction::TAG, BulkAction::UNTAG => $this->revertTagPivot($entity, $before),
                BulkAction::BOOKMARK => $this->revertBookmark($entity, $before),
            };

            return $this->writeAudit(
                self::REVERSAL_ACTION,
                $action,
                $entity,
                [],
                [],
                $actor,
                ['reversed_audit_log_id' => $log->id],
            );
        });
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, Model>
     */
    private function loadRows(string $entity, array $ids): array
    {
        return $this->query($entity)->whereIn('id', $ids)->get()->all();
    }

    private function query(string $entity): Builder
    {
        return match ($entity) {
            self::ENTITY_COMPANY => Company::query(),
            self::ENTITY_ROLE => Role::query(),
            self::ENTITY_LISTING => AggregatorListing::query(),
            default => throw new \InvalidArgumentException("Unsupported bulk entity [{$entity}]."),
        };
    }

    /**
     * @param  array<int, Model>  $rows
     * @param  array<string, mixed>  $params
     * @return array<int, array{id:int, field:string, old:mixed}>
     */
    private function applyChangeTier(array $rows, array $params): array
    {
        $tier = (string) ($params['tier'] ?? '');
        $before = [];

        foreach ($rows as $row) {
            /** @var Company $row */
            $before[] = ['id' => (int) $row->id, 'field' => 'tier', 'old' => $row->tier];
            $row->tier = $tier;
            $row->save();
        }

        return $before;
    }

    /**
     * @param  array<int, Model>  $rows
     * @param  array<string, mixed>  $params
     * @return array<int, array{id:int, field:string, old:mixed}>
     */
    private function applyTag(array $rows, string $entity, array $params): array
    {
        $tag = $this->resolveTag((string) ($params['tag'] ?? ''));
        $before = [];

        foreach ($rows as $row) {
            $attached = $this->taggables($row)->where('tags.id', $tag->id)->exists();
            $before[] = ['id' => (int) $row->id, 'field' => 'tag:'.$tag->id, 'old' => $attached];

            if (! $attached) {
                $this->taggables($row)->attach($tag->id);
            }
        }

        return $before;
    }

    /**
     * @param  array<int, Model>  $rows
     * @param  array<string, mixed>  $params
     * @return array<int, array{id:int, field:string, old:mixed}>
     */
    private function applyUntag(array $rows, string $entity, array $params): array
    {
        $tag = $this->resolveTag((string) ($params['tag'] ?? ''));
        $before = [];

        foreach ($rows as $row) {
            $attached = $this->taggables($row)->where('tags.id', $tag->id)->exists();
            $before[] = ['id' => (int) $row->id, 'field' => 'tag:'.$tag->id, 'old' => $attached];

            if ($attached) {
                $this->taggables($row)->detach($tag->id);
            }
        }

        return $before;
    }

    /**
     * @param  array<int, Model>  $rows
     * @return array<int, array{id:int, field:string, old:mixed}>
     */
    private function applyDismiss(string $entity, array $rows): array
    {
        $target = $entity === self::ENTITY_LISTING
            ? AggregatorListingStatus::DISMISSED->value
            : 'graveyard';
        $before = [];

        foreach ($rows as $row) {
            $old = $row->getAttribute('status');
            $old = $old instanceof \BackedEnum ? $old->value : $old;
            $before[] = ['id' => (int) $row->id, 'field' => 'status', 'old' => $old];
            $row->setAttribute('status', $target);
            $row->save();
        }

        return $before;
    }

    /**
     * @param  array<int, Model>  $rows
     * @return array<int, array{id:int, field:string, old:mixed}>
     */
    private function applyBookmark(string $entity, array $rows): array
    {
        $before = [];

        foreach ($rows as $row) {
            $existing = $row->bookmarks()->first();
            $before[] = [
                'id' => (int) $row->id,
                'field' => 'bookmark',
                'old' => $existing !== null ? (int) $existing->id : null,
            ];

            if ($existing === null) {
                $row->bookmarks()->create([
                    'notes' => null,
                    'expires_at' => CarbonImmutable::now()->addDays(self::BOOKMARK_DEFAULT_TTL_DAYS),
                ]);
            }
        }

        return $before;
    }

    /**
     * @param  array<int, Model>  $rows
     * @return array<int, array{id:int, field:string, old:mixed}>
     */
    private function applyMarkClosed(array $rows): array
    {
        $before = [];

        foreach ($rows as $row) {
            /** @var Role $row */
            $before[] = ['id' => (int) $row->id, 'field' => 'is_open', 'old' => (bool) $row->is_open];
            $row->is_open = false;
            $row->save();
        }

        return $before;
    }

    /**
     * @param  array<int, array{id:int, field:string, old:mixed}>  $before
     */
    private function revertColumn(string $entity, array $before, string $column): void
    {
        foreach ($before as $entry) {
            $row = $this->query($entity)->find($entry['id']);
            if ($row === null) {
                continue;
            }
            $row->setAttribute($column, $entry['old']);
            $row->save();
        }
    }

    /**
     * @param  array<int, array{id:int, field:string, old:mixed}>  $before
     */
    private function revertTagPivot(string $entity, array $before): void
    {
        foreach ($before as $entry) {
            $row = $this->query($entity)->find($entry['id']);
            if ($row === null) {
                continue;
            }
            $tagId = (int) substr((string) $entry['field'], strlen('tag:'));
            $wasAttached = (bool) $entry['old'];

            if ($wasAttached) {
                $this->taggables($row)->syncWithoutDetaching([$tagId]);
            } else {
                $this->taggables($row)->detach($tagId);
            }
        }
    }

    /**
     * @param  array<int, array{id:int, field:string, old:mixed}>  $before
     */
    private function revertBookmark(string $entity, array $before): void
    {
        foreach ($before as $entry) {
            // old === null means we created a bookmark; reverse by removing it.
            if ($entry['old'] !== null) {
                continue;
            }
            $row = $this->query($entity)->find($entry['id']);
            if ($row === null) {
                continue;
            }
            $row->bookmarks()->delete();
        }
    }

    private function resolveTag(string $name): Tag
    {
        $slug = Tag::normalizeSlug($name);

        /** @var Tag $tag */
        $tag = Tag::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => trim($name)],
        );

        return $tag;
    }

    private function taggables(Model $row): MorphToMany
    {
        /** @var Company|Role $row */
        return $row->tags();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, array{id:int, field:string, old:mixed}>  $before
     * @param  array<string, mixed>  $extraContext  Top-level context keys (e.g. reversed_audit_log_id on reversals).
     */
    private function writeAudit(
        string $auditAction,
        BulkAction $action,
        string $entity,
        array $params,
        array $before,
        User $actor,
        array $extraContext = [],
    ): AuditLog {
        /** @var AuditLog $log */
        $log = AuditLog::query()->create([
            'tenant_id' => $this->tenant->currentOrFail()->id,
            'actor_id' => $actor->getKey(),
            'action' => $auditAction,
            'outcome' => 'success',
            'ip' => null,
            'user_agent' => null,
            'context' => [
                'action' => $action->value,
                'entity' => $entity,
                'ids' => array_map(static fn (array $e): int => (int) $e['id'], $before),
                'params' => $params,
                'before' => $before,
                ...$extraContext,
            ],
        ]);

        return $log;
    }
}
