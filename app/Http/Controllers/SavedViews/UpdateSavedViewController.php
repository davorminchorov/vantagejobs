<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Http\Requests\SavedViews\UpdateSavedViewRequest;
use App\Models\SavedView;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class UpdateSavedViewController
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(UpdateSavedViewRequest $request, int $view): RedirectResponse
    {
        $savedView = SavedView::query()->find($view);

        if ($savedView === null) {
            throw new NotFoundHttpException;
        }

        if ($savedView->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        $this->db->transaction(function () use ($request, $savedView): void {
            if ($request->has('name')) {
                $savedView->name = (string) $request->string('name');
            }
            if ($request->has('sort_order')) {
                $savedView->sort_order = (int) $request->integer('sort_order');
            }
            if ($request->has('filters')) {
                $savedView->filters = (array) ($request->input('filters') ?? []);
            }
            if ($request->boolean('is_default') === true) {
                // App-layer enforcement: only one default per (user, entity).
                SavedView::query()
                    ->where('user_id', $savedView->user_id)
                    ->where('entity', $savedView->entity)
                    ->where('id', '!=', $savedView->id)
                    ->update(['is_default' => false]);
                $savedView->is_default = true;
            } elseif ($request->has('is_default') && $request->boolean('is_default') === false) {
                $savedView->is_default = false;
            }
            $savedView->save();
        });

        return back();
    }
}
