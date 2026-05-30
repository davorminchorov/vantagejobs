<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Models\Template;
use Illuminate\Http\RedirectResponse;

final readonly class DestroyController
{
    public function __invoke(int $template): RedirectResponse
    {
        /** @var Template $row */
        $row = Template::query()->findOrFail($template);

        $name = $row->name;
        $row->delete();

        return redirect()
            ->route('templates.index')
            ->with('success', "Deleted template {$name}.");
    }
}
