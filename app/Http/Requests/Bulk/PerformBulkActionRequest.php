<?php

declare(strict_types=1);

namespace App\Http\Requests\Bulk;

use App\Bulk\BulkActionExecutor;
use App\Enums\BulkAction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PerformBulkActionRequest extends FormRequest
{
    private const VALID_TIERS = ['A', 'B', 'C', 'graveyard'];

    /**
     * Which (entity => actions) pairs are permitted. Mirrors the matrix the
     * BulkActionExecutor knows how to apply and reverse.
     *
     * @var array<string, array<int, string>>
     */
    private const MATRIX = [
        BulkActionExecutor::ENTITY_COMPANY => ['change_tier', 'tag', 'untag', 'dismiss'],
        BulkActionExecutor::ENTITY_ROLE => ['tag', 'untag', 'bookmark', 'mark_closed'],
        BulkActionExecutor::ENTITY_LISTING => ['dismiss', 'bookmark'],
    ];

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
            'entity' => ['required', 'string', Rule::in(array_keys(self::MATRIX))],
            'action' => ['required', 'string', Rule::enum(BulkAction::class)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            'tier' => ['nullable', 'string', Rule::in(self::VALID_TIERS)],
            'tag' => ['nullable', 'string', 'min:1', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $entity = (string) $this->input('entity');
            $action = (string) $this->input('action');

            $allowed = self::MATRIX[$entity] ?? [];
            if ($entity !== '' && $action !== '' && ! in_array($action, $allowed, true)) {
                $validator->errors()->add('action', "Action [{$action}] is not allowed for entity [{$entity}].");
            }

            if ($action === BulkAction::CHANGE_TIER->value && $this->input('tier') === null) {
                $validator->errors()->add('tier', 'A tier is required when changing tier.');
            }

            if (in_array($action, [BulkAction::TAG->value, BulkAction::UNTAG->value], true)
                && trim((string) $this->input('tag', '')) === '') {
                $validator->errors()->add('tag', 'A tag is required when tagging or untagging.');
            }
        });
    }

    public function bulkAction(): BulkAction
    {
        return BulkAction::from((string) $this->input('action'));
    }

    /**
     * @return array<int, int>
     */
    public function ids(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = (array) $this->input('ids', []);

        return array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids)));
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        $params = [];
        if ($this->input('tier') !== null) {
            $params['tier'] = (string) $this->input('tier');
        }
        if ($this->input('tag') !== null) {
            $params['tag'] = trim((string) $this->input('tag'));
        }

        return $params;
    }
}
