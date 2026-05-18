<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\KeepListingAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class KeepListingController
{
    public function __construct(private KeepListingAction $action) {}

    public function __invoke(Request $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);
        $this->action->keep($row);

        return back();
    }
}
