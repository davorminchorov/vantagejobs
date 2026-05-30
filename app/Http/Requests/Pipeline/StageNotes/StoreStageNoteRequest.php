<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline\StageNotes;

use App\Enums\PipelineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStageNoteRequest extends FormRequest
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
            'stage' => ['required', 'string', Rule::enum(PipelineStatus::class)],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
