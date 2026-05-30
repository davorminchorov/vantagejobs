<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Enums\AtsType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class TriggerScrapersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->scopeIsValid($value)) {
                        $fail('The selected scope is invalid.');
                    }
                },
            ],
        ];
    }

    public function scope(): string
    {
        return (string) $this->validated('scope');
    }

    private function scopeIsValid(string $scope): bool
    {
        if (in_array($scope, ['all', 'ats', 'aggregators'], true)) {
            return true;
        }

        if (str_starts_with($scope, 'ats:')) {
            return AtsType::tryFrom(substr($scope, 4)) instanceof AtsType;
        }

        if (str_starts_with($scope, 'aggregator:')) {
            return AggregatorSourceType::tryFrom(substr($scope, 11)) instanceof AggregatorSourceType;
        }

        return false;
    }
}
