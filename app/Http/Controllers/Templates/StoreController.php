<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Requests\Templates\StoreTemplateRequest;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;

final readonly class StoreController
{
    public function __invoke(StoreTemplateRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $template = Template::query()->create($data);

        return redirect()
            ->route('templates.index')
            ->with('success', "Created template {$template->name}.");
    }
}
