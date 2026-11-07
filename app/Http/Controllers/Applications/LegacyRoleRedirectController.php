<?php

declare(strict_types=1);

namespace App\Http\Controllers\Applications;

use Illuminate\Http\RedirectResponse;

/**
 * GET /pipeline/roles/{role} moved into the Applications workspace as ?role=ID.
 */
final readonly class LegacyRoleRedirectController
{
    public function __invoke(int $role): RedirectResponse
    {
        return redirect()->route('applications.index', ['role' => $role]);
    }
}
