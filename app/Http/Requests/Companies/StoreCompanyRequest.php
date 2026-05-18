<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\AtsType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCompanyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $atsValues = array_map(fn (AtsType $t) => $t->value, AtsType::cases());

        return [
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:500', 'url'],
            'careers_url' => ['nullable', 'string', 'max:500', 'url'],
            'domain' => ['nullable', 'string', 'max:255'],
            'ats_type' => ['nullable', Rule::in($atsValues)],
            'ats_slug' => ['nullable', 'string', 'max:255', 'required_with:ats_type'],
            'tier' => ['nullable', Rule::in(['A', 'B', 'C', 'graveyard'])],
            'status' => ['nullable', Rule::in(['researching', 'watching', 'applied', 'in_process', 'rejected', 'offer', 'passed'])],
        ];
    }
}
