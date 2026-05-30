<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bulk;

use App\Bulk\BulkActionExecutor;
use App\Http\Requests\Bulk\PerformBulkActionRequest;
use Illuminate\Http\RedirectResponse;

final readonly class PerformBulkActionController
{
    public function __construct(private BulkActionExecutor $executor) {}

    public function __invoke(PerformBulkActionRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $log = $this->executor->perform(
            action: $request->bulkAction(),
            entity: (string) $request->input('entity'),
            ids: $request->ids(),
            params: $request->params(),
            actor: $actor,
        );

        $count = count($request->ids());
        $message = "Applied bulk action to {$count} ".($count === 1 ? 'item' : 'items').'.';

        // `success` rides the existing shared-flash plumbing for instant
        // feedback. `flash.undo` carries the structured payload the toast
        // needs to offer a one-click undo; the Inertia middleware must surface
        // it (see this slice's integration notes).
        return back()
            ->with('success', $message)
            ->with('flash.undo', [
                'audit_log_id' => $log->id,
                'count' => $count,
            ]);
    }
}
