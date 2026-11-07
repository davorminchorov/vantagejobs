<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\AddListingToPipelineAction;
use Illuminate\Http\RedirectResponse;

final readonly class AddListingToPipelineController
{
    public function __construct(private AddListingToPipelineAction $action) {}

    public function __invoke(int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);

        $result = $this->action->add($row);
        $role = $result['role'];
        $company = $result['company'];

        return match ($result['outcome']) {
            AddListingToPipelineAction::ADDED => back()->with(
                'success',
                "Added {$role?->title} at {$company?->name} to your applications.",
            ),
            // The shared flash payload only carries success and error.
            AddListingToPipelineAction::ALREADY_IN_PIPELINE => back()->with(
                'success',
                "{$role?->title} at {$company?->name} is already in your applications.",
            ),
            AddListingToPipelineAction::MISSING_TITLE => back()->with(
                'error',
                'This listing has no role title yet. Add one before adding it to your applications.',
            ),
            default => back()->with(
                'error',
                'This listing has no company name yet. Add one before adding it to your applications.',
            ),
        };
    }
}
