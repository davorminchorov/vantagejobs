<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline\Offers;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertOfferRequest extends FormRequest
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
            'currency' => ['required', 'string', 'size:3'],
            'base' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'bonus' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'signing_bonus' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'equity_shares' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'equity_strike' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'equity_assumed_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'vesting_years' => ['nullable', 'integer', 'min:1', 'max:10'],
            'decision_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
