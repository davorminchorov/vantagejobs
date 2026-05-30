<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Audit;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /admin/audit — paginated audit-log viewer.
 *
 * AuditLog deliberately does NOT use BelongsToTenant (failed logins fire before
 * tenant context resolves), so this controller scopes reads explicitly to the
 * current tenant AND surfaces null-tenant rows (failed logins, lockouts) which
 * are still operationally relevant to the operator.
 *
 * Filterable by action prefix, outcome, and date range. Filters are URL-state
 * and defensively parsed so a stale bookmark never blows up the page.
 */
final readonly class IndexController
{
    public function __construct(private TenantContext $tenant) {}

    public function __invoke(Request $request): Response
    {
        $filters = AuditLogListFilters::fromRequest($request);
        $currentTenantId = $this->tenant->current()?->id;

        $query = AuditLog::query()
            ->with(['actor:id,name,email'])
            ->where(function ($q) use ($currentTenantId): void {
                $q->whereNull('tenant_id');

                if ($currentTenantId !== null) {
                    $q->orWhere('tenant_id', $currentTenantId);
                }
            });

        $filters->applyTo($query);

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $items = array_map(static function (AuditLog $log): array {
            return [
                'id' => (int) $log->id,
                'action' => (string) $log->action,
                'outcome' => (string) $log->outcome,
                'actor' => $log->actor === null ? null : [
                    'id' => (int) $log->actor->id,
                    'name' => (string) $log->actor->name,
                    'email' => (string) $log->actor->email,
                ],
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
                'context' => $log->context ?? [],
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        }, $logs->items());

        return Inertia::render('Admin/Audit/Index', [
            'logs' => [
                'data' => $items,
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
                'links' => [
                    'prev' => $logs->previousPageUrl(),
                    'next' => $logs->nextPageUrl(),
                ],
            ],
            'filters' => $filters->toArray(),
            'options' => [
                'actions' => $this->distinctActions($currentTenantId),
                'outcomes' => AuditLogListFilters::VALID_OUTCOMES,
            ],
        ]);
    }

    /**
     * Distinct action values present for this tenant (plus null-tenant rows),
     * for the filter dropdown.
     *
     * @return list<string>
     */
    private function distinctActions(?int $currentTenantId): array
    {
        return AuditLog::query()
            ->where(function ($q) use ($currentTenantId): void {
                $q->whereNull('tenant_id');

                if ($currentTenantId !== null) {
                    $q->orWhere('tenant_id', $currentTenantId);
                }
            })
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(static fn (mixed $a): string => (string) $a)
            ->all();
    }
}
