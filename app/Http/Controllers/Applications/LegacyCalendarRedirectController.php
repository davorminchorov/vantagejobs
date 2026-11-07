<?php

declare(strict_types=1);

namespace App\Http\Controllers\Applications;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /calendar moved into the Applications workspace as view=upcoming.
 */
final readonly class LegacyCalendarRedirectController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $query = ['view' => 'upcoming'];

        if ($request->boolean('past')) {
            $query['past'] = 1;
        }

        return redirect()->route('applications.index', $query);
    }
}
