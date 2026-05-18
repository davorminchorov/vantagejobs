<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\BookmarkListingAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class BookmarkListingController
{
    public function __construct(private BookmarkListingAction $action) {}

    public function __invoke(Request $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);
        $this->action->bookmark($row);

        return back();
    }
}
