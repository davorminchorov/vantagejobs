<?php

declare(strict_types=1);

namespace App\Http\Requests\Insights;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional round details reviewed in the confirm dialog. Every field may be
 * left empty: the candidate's headline, link and publish date fill the gaps.
 */
final class ConfirmFundingCandidateRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
