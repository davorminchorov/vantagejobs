<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Enums\TemplateCategory;
use App\Models\Template;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexController
{
    public function __invoke(): Response
    {
        $templates = Template::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'body', 'updated_at'])
            ->map(fn (Template $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category->value,
                'body' => $template->body,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'categories' => array_map(
                static fn (TemplateCategory $category): string => $category->value,
                TemplateCategory::cases(),
            ),
        ]);
    }
}
