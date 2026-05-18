<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Http\Requests\Discover\SnoozeListingRequest;
use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\SnoozeListingAction;
use Illuminate\Http\RedirectResponse;

final readonly class SnoozeListingController
{
    public function __construct(private SnoozeListingAction $action) {}

    public function __invoke(SnoozeListingRequest $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);
        $this->action->snooze($row, $request->days());

        return back();
    }
}
