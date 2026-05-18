<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\ReparseAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Re-run the parser pipeline against the listing's stored raw payload,
 * overlay any existing ParseCorrection rows on top, and persist the
 * merged result. `?force=1` skips the correction overlay and returns
 * the raw parser output instead.
 */
final readonly class ReparseController
{
    public function __construct(private ReparseAction $action) {}

    public function __invoke(Request $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);

        $force = $request->boolean('force');

        $this->action->reparse($row, $force);

        return back();
    }
}
