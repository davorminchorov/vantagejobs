<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session management for the current user.
 *
 * v1 uses Redis as the session driver (see PRD §9, dev/prod runtime
 * divergence). Enumerating Redis sessions for "active sessions" is
 * possible via SCAN but fragile and deferred to Phase 6+ when there's
 * a real reason to list them. For now we expose only the
 * "sign out everywhere" action, which works regardless of driver via
 * Auth::logoutOtherDevices (it rotates the user's remember_token and
 * invalidates other session records on the next request).
 */
final readonly class SessionsController
{
    public function index(): Response
    {
        return Inertia::render('Settings/Sessions');
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        Auth::logoutOtherDevices($request->string('password')->toString());

        return redirect()
            ->route('settings.sessions')
            ->with('success', 'All other sessions have been signed out.');
    }
}
