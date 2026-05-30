<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use App\Enums\PipelineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStatusRequest extends FormRequest
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
            'role_id' => ['required', 'integer', 'min:1'],
            'pipeline_status' => ['required', Rule::enum(PipelineStatus::class)],
        ];
    }
}
