<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover\Sources;

use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Synchronous "run now" for one source. At personal-tool scale a single
 * aggregator fetch is <30s so blocking the HTTP request is acceptable. If
 * fetches start running longer (or run-all from the browser is wanted),
 * push to a queued job and Inertia-poll the source row instead.
 */
final readonly class RunSourceController
{
    public function __construct(private AggregatorOrchestrator $orchestrator) {}

    public function __invoke(Request $request, int $source): RedirectResponse
    {
        /** @var AggregatorSource $row */
        $row = AggregatorSource::query()->findOrFail($source);

        $run = $this->orchestrator->runForSource($row);

        return back()->with('flash', [
            'level' => $run->status === 'error' ? 'error' : 'info',
            'message' => "Ran {$row->name}: status={$run->status}, fetched={$run->fetched}, new={$run->new_count}.",
        ]);
    }
}
