<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SavedView;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenant = app(TenantContext::class)->current();

        return [
            ...parent::share($request),
            'auth' => fn () => [
                'user' => $this->userFor($request),
            ],
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
            ],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                // Structured payload for the bulk-action undo toast; see
                // PerformBulkActionController.
                'undo' => $request->session()->get('flash.undo'),
            ],
            'savedViews' => fn (): array => $this->savedViewsFor($request),
            'debug' => app()->environment('local'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userFor(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        /** @var User $user */
        return [
            ...$user->toArray(),
            'is_admin' => $user->hasRole('admin'),
        ];
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     entity: string,
     *     filters: array<string, mixed>,
     *     sort_order: int,
     *     is_default: bool
     * }>
     */
    private function savedViewsFor(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return SavedView::query()
            ->where('user_id', $user->id)
            ->orderBy('entity')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SavedView $view): array => [
                'id' => (int) $view->id,
                'name' => (string) $view->name,
                'entity' => (string) $view->entity,
                'filters' => (array) $view->filters,
                'sort_order' => (int) $view->sort_order,
                'is_default' => (bool) $view->is_default,
            ])
            ->all();
    }
}
