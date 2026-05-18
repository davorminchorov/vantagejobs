<?php

declare(strict_types=1);

namespace App\Http\Requests\Import;

use App\Imports\CsvImporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RunImportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $headers = (array) $this->session()->get('import.headers', []);
        $allowedHeaders = array_values(array_unique(array_filter($headers, 'is_string')));

        $rules = [
            'mapping' => ['required', 'array'],
        ];
        foreach (CsvImporter::FIELDS as $field) {
            $required = in_array($field, CsvImporter::REQUIRED_FIELDS, true);
            $rules["mapping.{$field}"] = [
                $required ? 'required' : 'nullable',
                'nullable',
                'string',
                Rule::in($allowedHeaders),
            ];
        }

        return $rules;
    }
}
