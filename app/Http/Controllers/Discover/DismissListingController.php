<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\DismissListingAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class DismissListingController
{
    public function __construct(private DismissListingAction $action) {}

    public function __invoke(Request $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);
        $result = $this->action->dismiss($row);

        if ($result->ruleFlippedToAutoDismiss && $result->rule !== null) {
            return back()->with('flash', [
                'level' => 'info',
                'message' => "Auto-dismissing future listings from {$result->rule->display_name}.",
            ]);
        }

        return back();
    }
}
