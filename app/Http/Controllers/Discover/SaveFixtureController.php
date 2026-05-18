<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover;

use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\SaveFixtureAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Save as fixture" one-click from a Discover card, per PRD §6.1.5.
 *
 * Writes the listing + its current post-correction parsed values out as
 * a JSON fixture under tests/Fixtures/parsers/{source}/{slug}.json, so a
 * mis-parse the user just corrected on a card becomes a regression test
 * for the next parser run.
 */
final readonly class SaveFixtureController
{
    public function __construct(private SaveFixtureAction $action) {}

    public function __invoke(Request $request, int $listing): RedirectResponse
    {
        /** @var AggregatorListing $row */
        $row = AggregatorListing::query()->findOrFail($listing);

        $result = $this->action->save($row);

        return back()->with('flash', [
            'level' => 'success',
            'message' => "Fixture saved to {$result['path']}",
        ]);
    }
}
