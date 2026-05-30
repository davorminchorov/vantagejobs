<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bulk;

use App\Bulk\BulkActionExecutor;
use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class UndoBulkActionController
{
    public const UNDO_WINDOW_SECONDS = 60;

    public function __construct(
        private BulkActionExecutor $executor,
        private TenantContext $tenant,
    ) {}

    public function __invoke(Request $request, int $auditLog): RedirectResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        // AuditLog has no tenant global scope, so filter explicitly.
        $log = AuditLog::query()
            ->where('id', $auditLog)
            ->where('tenant_id', $this->tenant->currentOrFail()->id)
            ->where('action', BulkActionExecutor::ACTION)
            ->first();

        if ($log === null) {
            return back()->with('error', 'Nothing to undo.');
        }

        $deadline = CarbonImmutable::parse($log->created_at)->addSeconds(self::UNDO_WINDOW_SECONDS);
        if (CarbonImmutable::now()->greaterThan($deadline)) {
            return back()->with('error', 'The undo window has expired.');
        }

        // Guard against double-undo: a reversal row referencing this log.
        $alreadyReversed = AuditLog::query()
            ->where('tenant_id', $log->tenant_id)
            ->where('action', BulkActionExecutor::REVERSAL_ACTION)
            ->where('context->reversed_audit_log_id', $log->id)
            ->exists();

        if ($alreadyReversed) {
            return back()->with('success', 'This action was already undone.');
        }

        $this->executor->undo($log, $actor);

        return back()->with('success', 'Bulk action undone.');
    }
}
