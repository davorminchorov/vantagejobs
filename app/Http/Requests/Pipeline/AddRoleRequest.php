<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

final class AddRoleRequest extends FormRequest
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
        return [];
    }
}
