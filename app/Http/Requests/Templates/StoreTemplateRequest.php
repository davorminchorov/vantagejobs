<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Enums\TemplateCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $categoryValues = array_map(
            static fn (TemplateCategory $category): string => $category->value,
            TemplateCategory::cases(),
        );

        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in($categoryValues)],
            'body' => ['required', 'string', 'max:20000'],
        ];
    }
}
