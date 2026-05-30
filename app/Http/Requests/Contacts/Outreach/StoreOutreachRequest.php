<?php

declare(strict_types=1);

namespace App\Http\Requests\Contacts\Outreach;

use App\Enums\OutreachChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreOutreachRequest extends FormRequest
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
            'channel' => ['required', new Enum(OutreachChannel::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
