<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover\Sources;

use App\Models\AggregatorSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class ToggleSourceController
{
    public function __invoke(Request $request, int $source): RedirectResponse
    {
        /** @var AggregatorSource $row */
        $row = AggregatorSource::query()->findOrFail($source);
        $row->enabled = ! $row->enabled;
        $row->save();

        return back();
    }
}
