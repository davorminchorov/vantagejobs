<?php

declare(strict_types=1);

namespace App\Http\Requests\SavedViews;

use App\Models\SavedView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSavedViewRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'entity' => ['required', 'string', Rule::in(SavedView::VALID_ENTITIES)],
            'filters' => ['nullable', 'array'],
        ];
    }
}
