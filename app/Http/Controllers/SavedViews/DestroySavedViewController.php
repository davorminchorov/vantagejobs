<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DestroySavedViewController
{
    public function __invoke(Request $request, int $view): RedirectResponse
    {
        $savedView = SavedView::query()->find($view);

        if ($savedView === null) {
            throw new NotFoundHttpException;
        }

        if ($savedView->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        $savedView->delete();

        return back();
    }
}
