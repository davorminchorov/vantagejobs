<?php

declare(strict_types=1);

namespace App\Http\Controllers\Roles;

use App\Models\Role;
use Illuminate\Http\RedirectResponse;

final readonly class RestoreController
{
    public function __invoke(int $role): RedirectResponse
    {
        /** @var Role $row */
        $row = Role::query()->findOrFail($role);
        $row->forceFill(['dismissed_at' => null])->save();

        return back();
    }
}
