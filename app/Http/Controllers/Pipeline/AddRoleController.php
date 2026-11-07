<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Http\Requests\Pipeline\AddRoleRequest;
use App\Models\Role;
use App\Pipeline\AddRoleToPipeline;
use Illuminate\Http\RedirectResponse;

final readonly class AddRoleController
{
    public function __construct(private AddRoleToPipeline $addRole) {}

    public function __invoke(AddRoleRequest $request, Role $role): RedirectResponse
    {
        // Already opted into the pipeline: nothing to do, keep history clean.
        if (! $this->addRole->add($role)) {
            return back()
                ->with('info', "{$role->title} is already in your applications.");
        }

        return back()
            ->with('success', "Added {$role->title} to your applications.");
    }
}
