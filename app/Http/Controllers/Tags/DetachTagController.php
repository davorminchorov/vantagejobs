<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tags;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;

final readonly class DetachTagController
{
    public function __invoke(Company $company, Tag $tag): RedirectResponse
    {
        $company->tags()->detach($tag->id);

        return back();
    }
}
