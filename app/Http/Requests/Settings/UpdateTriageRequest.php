<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'auto_dismiss_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'dismissed_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'listing_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
