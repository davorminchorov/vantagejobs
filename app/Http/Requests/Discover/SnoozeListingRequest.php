<?php

declare(strict_types=1);

namespace App\Http\Requests\Discover;

use App\Scrapers\Aggregator\SnoozeListingAction;
use Illuminate\Foundation\Http\FormRequest;

final class SnoozeListingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function days(): int
    {
        $value = $this->input('days');

        return is_numeric($value) ? (int) $value : SnoozeListingAction::DEFAULT_DAYS;
    }
}
