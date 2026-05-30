<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Requests\Templates\UpdateTemplateRequest;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;

final readonly class UpdateController
{
    public function __invoke(UpdateTemplateRequest $request, int $template): RedirectResponse
    {
        /** @var Template $row */
        $row = Template::query()->findOrFail($template);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $row->fill($data)->save();

        return redirect()
            ->route('templates.index')
            ->with('success', "Updated template {$row->name}.");
    }
}
