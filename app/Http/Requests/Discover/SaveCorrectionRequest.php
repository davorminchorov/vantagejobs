<?php

declare(strict_types=1);

namespace App\Http\Requests\Discover;

use App\Scrapers\Aggregator\ApplyCorrectionAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates inline correction payloads from /discover cards.
 *
 * Per PRD §6.1.5: only fields surfaced on the triage card are correctable.
 * Per-field shape constraints keep us honest — a comp range correction
 * must be an integer, a stack-tag correction must be a list of strings.
 */
final class SaveCorrectionRequest extends FormRequest
{
    private const REMOTE_TYPES = ['remote', 'hybrid', 'on_site', 'unknown'];

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        $field = (string) $this->input('field', '');
        $valueRules = $this->valueRulesFor($field);

        return [
            'field' => ['required', 'string', Rule::in(ApplyCorrectionAction::FIELDS)],
            'new_value' => $valueRules,
            ...($field === 'stack_tags' ? ['new_value.*' => ['nullable', 'string', 'max:64']] : []),
        ];
    }

    public function field(): string
    {
        return (string) $this->input('field');
    }

    public function newValue(): mixed
    {
        $value = $this->input('new_value');
        $field = $this->field();

        return match ($field) {
            'comp_min_usd', 'comp_max_usd' => $this->coerceNullableInt($value),
            'stack_tags' => is_array($value) ? array_values(array_unique(array_filter(array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $value), static fn (string $v): bool => $v !== ''))) : [],
            default => $value,
        };
    }

    /**
     * @return array<int, string|ValidationRule>
     */
    private function valueRulesFor(string $field): array
    {
        return match ($field) {
            'company_name', 'role_title' => ['required', 'string', 'max:255'],
            'seniority', 'region' => ['nullable', 'string', 'max:64'],
            'remote_type' => ['required', 'string', Rule::in(self::REMOTE_TYPES)],
            'comp_min_usd', 'comp_max_usd' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'stack_tags' => ['present', 'array', 'max:64'],
            default => ['nullable'],
        };
    }

    private function coerceNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
