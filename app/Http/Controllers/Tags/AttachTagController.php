<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tags;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class AttachTagController
{
    public function __invoke(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:60'],
        ]);

        $name = trim((string) $validated['name']);
        $slug = Tag::normalizeSlug($name);

        if ($slug === '') {
            return back()->withErrors(['name' => 'Tag is empty after normalization.']);
        }

        $tag = Tag::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name],
        );

        // Idempotent: no-op if already attached.
        $company->tags()->syncWithoutDetaching([$tag->id]);

        return back();
    }
}
