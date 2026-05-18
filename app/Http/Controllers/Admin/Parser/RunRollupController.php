<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Parser;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\RedirectResponse;

/**
 * One-shot trigger for parser:rollup-confidence from the dashboard's
 * empty-state. Without this button, a freshly migrated install would show
 * a blank dashboard until the scheduler ran at 02:30 UTC.
 *
 * Runs synchronously — the rollup is bounded by source × field × window
 * (about 600 percentile aggregations against an indexed jsonb column) and
 * finishes comfortably inside a request.
 */
final readonly class RunRollupController
{
    public function __construct(private ConsoleKernel $artisan) {}

    public function __invoke(): RedirectResponse
    {
        $this->artisan->call('parser:rollup-confidence');

        return redirect()->route('admin.parser.index')->with(
            'success',
            'Confidence rollup refreshed.'
        );
    }
}
