<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

final class AddFundingRequest extends FormRequest
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
            'stage' => ['nullable', 'string', 'max:32'],
            'amount_usd' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'raised_at' => ['nullable', 'date'],
            'url' => ['nullable', 'url', 'max:1024'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
