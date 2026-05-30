<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'digest_send_time' => ['required', 'string', 'date_format:H:i'],
            'dnd_start' => ['required', 'string', 'date_format:H:i'],
            'dnd_end' => ['required', 'string', 'date_format:H:i'],
            'digest_skip_empty' => ['required', 'boolean'],
            'notification_email' => ['required', 'string', 'email', 'max:255'],
            'weekly_summary_enabled' => ['required', 'boolean'],
        ];
    }
}
