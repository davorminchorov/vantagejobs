<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bookmarks;

use App\Models\Bookmark;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class DeleteBookmarkController
{
    public function __invoke(Request $request, int $bookmark): RedirectResponse
    {
        /** @var Bookmark $row */
        $row = Bookmark::query()->findOrFail($bookmark);
        $row->delete();

        return back();
    }
}
