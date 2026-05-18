<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use Inertia\Inertia;
use Inertia\Response;

final readonly class UploadFormController
{
    public function __invoke(): Response
    {
        return Inertia::render('Import/Upload');
    }
}
