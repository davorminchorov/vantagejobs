<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Whitelisted, defensively-parsed filter payload for the /contacts list view.
 *
 * Unknown query keys (or garbage values) are silently dropped so a hand-typed
 * or stale URL never blows up the page.
 *
 * @phpstan-type FilterPayload array{
 *     company_id: ?int,
 *     role_title: ?string,
 *     last_touch: ?string,
 *     q: ?string
 * }
 */
final readonly class ContactListFilters
{
    public const VALID_LAST_TOUCH = [
        'never',
        'last_7d',
        'last_30d',
        'older_than_30d',
    ];

    public function __construct(
        public ?int $companyId,
        public ?string $roleTitle,
        public ?string $lastTouch,
        public ?string $q,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            companyId: self::positiveInt($input['company_id'] ?? null),
            roleTitle: self::nonEmptyString($input['role_title'] ?? null),
            lastTouch: self::inSet($input['last_touch'] ?? null, self::VALID_LAST_TOUCH),
            q: self::nonEmptyString($input['q'] ?? null),
        );
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->query());
    }

    /**
     * @param  Builder<\App\Models\Contact>  $query
     * @return Builder<\App\Models\Contact>
     */
    public function applyTo(Builder $query): Builder
    {
        if ($this->companyId !== null) {
            $query->where('company_id', $this->companyId);
        }

        if ($this->roleTitle !== null) {
            $query->where('role_title', 'ilike', '%'.$this->roleTitle.'%');
        }

        $this->applyLastTouchRange($query);

        if ($this->q !== null) {
            $needle = '%'.$this->q.'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->where('name', 'ilike', $needle)
                    ->orWhere('email', 'ilike', $needle)
                    ->orWhere('role_title', 'ilike', $needle);
            });
        }

        return $query;
    }

    /**
     * @return FilterPayload
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'role_title' => $this->roleTitle,
            'last_touch' => $this->lastTouch,
            'q' => $this->q,
        ];
    }

    /**
     * @param  Builder<\App\Models\Contact>  $query
     */
    private function applyLastTouchRange(Builder $query): void
    {
        if ($this->lastTouch === null) {
            return;
        }

        $now = CarbonImmutable::now();

        match ($this->lastTouch) {
            'never' => $query->whereNull('last_touch'),
            'last_7d' => $query->where('last_touch', '>=', $now->subDays(7)),
            'last_30d' => $query->where('last_touch', '>=', $now->subDays(30)),
            'older_than_30d' => $query->where('last_touch', '<', $now->subDays(30)),
            default => null,
        };
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param  list<string>  $set
     */
    private static function inSet(mixed $value, array $set): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, $set, true) ? $value : null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
