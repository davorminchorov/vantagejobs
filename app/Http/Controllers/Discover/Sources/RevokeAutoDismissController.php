<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover\Sources;

use App\Models\DismissedCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Flips an auto-dismiss rule off without deleting the dismiss-count
 * history. If the user dismisses the same employer 3 more times the rule
 * re-arms on the threshold-cross. PRD §6.4.4 explicitly preserves the
 * audit trail.
 */
final readonly class RevokeAutoDismissController
{
    public function __invoke(Request $request, int $rule): RedirectResponse
    {
        /** @var DismissedCompany $row */
        $row = DismissedCompany::query()->findOrFail($rule);
        $row->auto_dismiss = false;
        $row->save();

        return back();
    }
}
