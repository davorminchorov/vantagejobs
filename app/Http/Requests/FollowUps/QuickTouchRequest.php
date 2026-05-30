<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUps;

use App\Enums\OutreachChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * One-click "log a touch" from the follow-up queue.
 *
 * Channel and note are optional: the queue's quick action exists to clear a
 * follow-up flag with a single click, so an empty body simply records that the
 * contact was touched today. Channel defaults to OTHER when omitted.
 */
final class QuickTouchRequest extends FormRequest
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
            'channel' => ['nullable', new Enum(OutreachChannel::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
