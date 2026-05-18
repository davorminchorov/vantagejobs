<?php

declare(strict_types=1);

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

final class UploadCsvRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ];
    }
}
