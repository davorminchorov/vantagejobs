<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Inertia\Inertia;
use Inertia\Response;

final readonly class PasswordController
{
    public function __invoke(): Response
    {
        return Inertia::render('Settings/Password');
    }
}
