<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class TwoFactorController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/TwoFactor', [
            'two_factor_enabled' => $user?->two_factor_secret !== null,
            'two_factor_confirmed' => $user?->two_factor_confirmed_at !== null,
        ]);
    }
}
