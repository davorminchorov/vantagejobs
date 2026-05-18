<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Http\Requests\Discover\SaveCorrectionRequest;
use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\ApplyCorrectionAction;
use Illuminate\Http\RedirectResponse;

final readonly class SaveCorrectionController
{
    public function __construct(private ApplyCorrectionAction $action) {}

    public function __invoke(SaveCorrectionRequest $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);

        $this->action->apply($row, $request->field(), $request->newValue());

        return back();
    }
}
