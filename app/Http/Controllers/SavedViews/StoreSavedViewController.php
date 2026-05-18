<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Http\Requests\SavedViews\StoreSavedViewRequest;
use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;

final readonly class StoreSavedViewController
{
    public function __invoke(StoreSavedViewRequest $request): RedirectResponse
    {
        $user = $request->user();

        $sortOrder = (int) SavedView::query()
            ->where('user_id', $user->id)
            ->where('entity', $request->string('entity'))
            ->max('sort_order') + 1;

        SavedView::query()->create([
            'user_id' => $user->id,
            'name' => (string) $request->string('name'),
            'entity' => (string) $request->string('entity'),
            'filters' => (array) ($request->input('filters') ?? []),
            'sort_order' => $sortOrder,
            'is_default' => false,
        ]);

        return back();
    }
}
