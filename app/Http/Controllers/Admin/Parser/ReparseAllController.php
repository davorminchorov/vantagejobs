<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Parser;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Triggers parser:reparse-all from the /admin/parser dashboard.
 *
 * Until step 10 lands the command is a stub that just logs. The dashboard
 * button still needs the confirmation pattern in place so the eventual
 * destructive operation doesn't ship with an unguarded one-click trigger.
 *
 * Requires the user to type "YES" into a confirmation field — a plain
 * checkbox would be too easy to misclick past.
 */
final readonly class ReparseAllController
{
    public function __construct(private ConsoleKernel $artisan) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $confirm = (string) $request->input('confirm', '');

        if ($confirm !== 'YES') {
            return redirect()->route('admin.parser.index')->with(
                'error',
                'Re-parse all aborted: confirmation phrase missing. Type YES to proceed.'
            );
        }

        $this->artisan->call('parser:reparse-all');

        return redirect()->route('admin.parser.index')->with(
            'success',
            'Re-parse all queued. Watch /admin/parser for the next rollup tick.'
        );
    }
}
