<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class CancelImportController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->forget(['import.headers', 'import.rows', 'import.mapping', 'import.original_name']);

        return redirect()->route('import.upload');
    }
}
