<?php

declare(strict_types=1);

namespace App\Http\Requests\SavedViews;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSavedViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'filters' => ['sometimes', 'array'],
        ];
    }
}
