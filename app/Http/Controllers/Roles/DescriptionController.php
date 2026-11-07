<?php

declare(strict_types=1);

namespace App\Http\Controllers\Roles;

use App\Models\Role;
use App\Roles\RoleDescriptionExtractor;
use Illuminate\Http\JsonResponse;

final readonly class DescriptionController
{
    public function __construct(private RoleDescriptionExtractor $descriptions) {}

    public function __invoke(int $role): JsonResponse
    {
        /** @var Role $row */
        $row = Role::query()->findOrFail($role);

        return response()->json(['body_text' => $this->descriptions->for($row)]);
    }
}
