<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Whitelisted, defensively-parsed filter payload for /admin/audit.
 *
 * Unknown query keys (or garbage values) are silently dropped so a bookmarked
 * URL or a typo never blows up the page. Filters are URL-state (plain query
 * string), matching ScrapeRunListFilters.
 *
 * @phpstan-type FilterPayload array{
 *     action: ?string,
 *     outcome: ?string,
 *     date_from: ?string,
 *     date_to: ?string
 * }
 */
final readonly class AuditLogListFilters
{
    public const VALID_OUTCOMES = ['success', 'failure'];

    public function __construct(
        public ?string $action,
        public ?string $outcome,
        public ?string $dateFrom,
        public ?string $dateTo,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            action: self::parseString($request->query('action')),
            outcome: self::parseEnum($request->query('outcome'), self::VALID_OUTCOMES),
            dateFrom: self::parseDate($request->query('date_from')),
            dateTo: self::parseDate($request->query('date_to')),
        );
    }

    /**
     * @param  Builder<\App\Models\AuditLog>  $query
     */
    public function applyTo(Builder $query): void
    {
        if ($this->action !== null) {
            $query->where('action', 'like', $this->action.'%');
        }

        if ($this->outcome !== null) {
            $query->where('outcome', $this->outcome);
        }

        if ($this->dateFrom !== null) {
            $query->where('created_at', '>=', CarbonImmutable::parse($this->dateFrom)->startOfDay());
        }

        if ($this->dateTo !== null) {
            $query->where('created_at', '<=', CarbonImmutable::parse($this->dateTo)->endOfDay());
        }
    }

    /**
     * @return array{action: ?string, outcome: ?string, date_from: ?string, date_to: ?string}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'outcome' => $this->outcome,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ];
    }

    private static function parseString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || $value === 'all') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function parseEnum(mixed $value, array $allowed): ?string
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
