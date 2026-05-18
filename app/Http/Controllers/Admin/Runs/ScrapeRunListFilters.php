<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Whitelisted, defensively-parsed filter payload for /admin/runs.
 *
 * Unknown query keys (or keys with garbage values) are silently dropped so
 * an old bookmarked URL or a typo never blows up the page. All filters are
 * URL-state (plain query string), matching the convention in
 * Companies/CompanyListFilters.
 *
 * @phpstan-type FilterPayload array{
 *     date_from: ?string,
 *     date_to: ?string,
 *     source_kind: ?string,
 *     source_id: list<string>,
 *     status: ?string,
 *     failed_24h: bool
 * }
 */
final readonly class ScrapeRunListFilters
{
    public const VALID_SOURCE_KINDS = ['ats', 'aggregator'];

    public const VALID_STATUSES = ['completed', 'partial', 'error', 'running'];

    /**
     * Multi-value source key. Stored as composite "kind:id" strings (e.g.
     * "ats:42" or "aggregator:hackernews") so a single field can address both
     * ATS companies and aggregator source identifiers without ambiguity.
     *
     * @param  list<string>  $sourceId
     */
    public function __construct(
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $sourceKind,
        public array $sourceId,
        public ?string $status,
        public bool $failed24h,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            dateFrom: self::parseDate($request->query('date_from')),
            dateTo: self::parseDate($request->query('date_to')),
            sourceKind: self::parseEnum($request->query('source_kind'), self::VALID_SOURCE_KINDS),
            sourceId: self::parseList($request->query('source_id')),
            status: self::parseEnum($request->query('status'), self::VALID_STATUSES),
            failed24h: self::parseBool($request->query('failed_24h')),
        );
    }

    /**
     * @param  Builder<\App\Models\ScrapeRun>  $query
     */
    public function applyTo(Builder $query): void
    {
        if ($this->dateFrom !== null) {
            $query->where('started_at', '>=', CarbonImmutable::parse($this->dateFrom)->startOfDay());
        }

        if ($this->dateTo !== null) {
            $query->where('started_at', '<=', CarbonImmutable::parse($this->dateTo)->endOfDay());
        }

        if ($this->sourceKind !== null) {
            $query->where('source_kind', $this->sourceKind);
        }

        if ($this->sourceId !== []) {
            $query->where(function (Builder $q): void {
                foreach ($this->sourceId as $pair) {
                    [$kind, $id] = self::splitSourcePair($pair);

                    if ($kind === null || $id === null) {
                        continue;
                    }

                    $q->orWhere(function (Builder $inner) use ($kind, $id): void {
                        $inner->where('source_kind', $kind);

                        if ($kind === 'ats') {
                            $inner->where('company_id', (int) $id);
                        } else {
                            $inner->where('source', $id);
                        }
                    });
                }
            });
        }

        if ($this->status !== null) {
            if ($this->status === 'running') {
                $query->whereIn('status', ['running']);
            } else {
                $query->where('status', $this->status);
            }
        }

        if ($this->failed24h) {
            $query
                ->where('started_at', '>=', CarbonImmutable::now()->subDay())
                ->whereIn('status', ['error', 'partial']);
        }
    }

    /**
     * @return array{
     *     date_from: ?string,
     *     date_to: ?string,
     *     source_kind: ?string,
     *     source_id: list<string>,
     *     status: ?string,
     *     failed_24h: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'source_kind' => $this->sourceKind,
            'source_id' => $this->sourceId,
            'status' => $this->status,
            'failed_24h' => $this->failed24h,
        ];
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

    /**
     * @return list<string>
     */
    private static function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $items = array_values(array_filter($value, 'is_string'));
        } elseif (is_string($value) && $value !== '') {
            $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        } else {
            $items = [];
        }

        // Whitelist to "kind:id" shape.
        return array_values(array_filter($items, static function (string $v): bool {
            $parts = explode(':', $v, 2);

            return count($parts) === 2
                && in_array($parts[0], self::VALID_SOURCE_KINDS, true)
                && $parts[1] !== '';
        }));
    }

    private static function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function splitSourcePair(string $pair): array
    {
        $parts = explode(':', $pair, 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        [$kind, $id] = $parts;

        if (! in_array($kind, self::VALID_SOURCE_KINDS, true) || $id === '') {
            return [null, null];
        }

        return [$kind, $id];
    }
}
