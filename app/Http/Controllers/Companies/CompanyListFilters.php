<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Whitelisted, defensively-parsed filter payload for the /companies list view.
 *
 * Unknown query keys (or keys with garbage values) are silently dropped so a
 * saved view that references a stale filter never blows up the page.
 *
 * Supports multi-value selection (`?tier[]=A&tier[]=B`) and CSV
 * (`?tier=A,B`) for hand-typed URLs.
 *
 * @phpstan-type FilterPayload array{
 *     tier: list<string>,
 *     status: list<string>,
 *     ats_type: list<string>,
 *     stage: list<string>,
 *     size: list<string>,
 *     remote_policy: list<string>,
 *     primary_stack: list<string>,
 *     has_open_roles: ?bool,
 *     last_checked: ?string,
 *     domain: ?string,
 *     q: ?string,
 *     recently_funded: ?bool
 * }
 */
final readonly class CompanyListFilters
{
    public const VALID_TIERS = ['A', 'B', 'C', 'graveyard'];

    public const VALID_STATUSES = [
        'researching',
        'watching',
        'applied',
        'in_process',
        'rejected',
        'offer',
        'passed',
        'graveyard',
    ];

    public const VALID_LAST_CHECKED = [
        'never',
        'last_24h',
        'last_7d',
        'last_30d',
        'older_than_30d',
    ];

    /**
     * @param  list<string>  $tier
     * @param  list<string>  $status
     * @param  list<string>  $atsType
     * @param  list<string>  $stage
     * @param  list<string>  $size
     * @param  list<string>  $remotePolicy
     * @param  list<string>  $primaryStack
     */
    public function __construct(
        public array $tier,
        public array $status,
        public array $atsType,
        public array $stage,
        public array $size,
        public array $remotePolicy,
        public array $primaryStack,
        public ?bool $hasOpenRoles,
        public ?string $lastChecked,
        public ?string $domain,
        public ?string $q,
        public bool $graveyard,
        public ?bool $recentlyFunded,
    ) {}

    /**
     * Parse from any source of `array<string, mixed>` (Request or saved-view payload).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $validAtsTypes = array_map(static fn (AtsType $t) => $t->value, AtsType::cases());

        $hasOpenRoles = $input['has_open_roles'] ?? null;
        $hasOpenRolesBool = match (is_string($hasOpenRoles) ? $hasOpenRoles : $hasOpenRoles) {
            '1', 1, true, 'true', 'yes' => true,
            '0', 0, false, 'false', 'no' => false,
            default => null,
        };

        $graveyardRaw = $input['graveyard'] ?? null;
        $graveyard = match (is_string($graveyardRaw) ? $graveyardRaw : $graveyardRaw) {
            '1', 1, true, 'true', 'yes' => true,
            default => false,
        };

        $recentlyFundedRaw = $input['recently_funded'] ?? null;
        $recentlyFunded = match (is_string($recentlyFundedRaw) ? $recentlyFundedRaw : $recentlyFundedRaw) {
            '1', 1, true, 'true', 'yes' => true,
            '0', 0, false, 'false', 'no' => false,
            default => null,
        };

        return new self(
            tier: self::filterMulti($input['tier'] ?? null, self::VALID_TIERS),
            status: self::filterMulti($input['status'] ?? null, self::VALID_STATUSES),
            atsType: self::filterMulti($input['ats_type'] ?? null, $validAtsTypes),
            stage: self::filterMulti($input['stage'] ?? null, null),
            size: self::filterMulti($input['size'] ?? null, null),
            remotePolicy: self::filterMulti($input['remote_policy'] ?? null, null),
            primaryStack: self::filterMulti($input['primary_stack'] ?? null, null),
            hasOpenRoles: $hasOpenRolesBool,
            lastChecked: self::inSet($input['last_checked'] ?? null, self::VALID_LAST_CHECKED),
            domain: self::nonEmptyString($input['domain'] ?? null),
            q: self::nonEmptyString($input['q'] ?? null),
            graveyard: $graveyard,
            recentlyFunded: $recentlyFunded,
        );
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->query());
    }

    /**
     * Apply this filter set to an Eloquent builder. Always safe to call: any
     * filter dimension that has no value is a no-op.
     */
    public function applyTo(Builder $query): Builder
    {
        // Default queries exclude graveyard. The toggle flips to graveyard-only
        // so the /companies?graveyard=1 view shares this controller.
        if ($this->graveyard) {
            $query->where('is_active', false);
        } else {
            $query->where('is_active', true);
        }

        if ($this->tier !== []) {
            $query->whereIn('tier', $this->tier);
        }
        if ($this->status !== []) {
            $query->whereIn('status', $this->status);
        }
        if ($this->atsType !== []) {
            $query->whereIn('ats_type', $this->atsType);
        }
        if ($this->hasOpenRoles === true) {
            $query->whereHas('roles', fn ($q) => $q->where('is_open', true));
        } elseif ($this->hasOpenRoles === false) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('is_open', true));
        }

        $this->applyLastCheckedRange($query);

        if ($this->domain !== null) {
            $needle = '%'.$this->domain.'%';
            $query->where('domain', 'ilike', $needle);
        }

        if ($this->q !== null) {
            $needle = '%'.$this->q.'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'ilike', $needle)
                    ->orWhere('domain', 'ilike', $needle)
                    ->orWhere('ats_slug', 'ilike', $needle);
            });
        }

        // Phase 4 step 16: `stage` is now wired to companies.funding_stage
        // (free-form text per user input, so no whitelist — saved views
        // round-trip whatever the user picked).
        if ($this->stage !== []) {
            $query->whereIn('funding_stage', $this->stage);
        }

        // Phase 4 step 16: "recently funded" = raised within the last 18mo.
        if ($this->recentlyFunded === true) {
            $query->where('funding_last_raised_at', '>=', CarbonImmutable::now()->subMonths(18));
        }

        // TODO(phase 5+): `size` filter — companies table has no team_size
        // column yet. Accepted into the dataclass for saved-view round-trip
        // but applies no narrowing.
        // TODO(phase 5+): `remote_policy` lives at the role/listing level
        // (aggregator_listings), not on the company itself. Needs an
        // aggregation rollup before it can filter the company list.
        // TODO(phase 5+): `primary_stack` would require aggregating
        // aggregator_listings.stack_tags to a per-company primary. Deferred.

        return $query;
    }

    /**
     * Canonical wire shape for sharing back to the Inertia page and for
     * persistence in saved_views.filters.
     *
     * @return array{
     *     tier: list<string>,
     *     status: list<string>,
     *     ats_type: list<string>,
     *     stage: list<string>,
     *     size: list<string>,
     *     remote_policy: list<string>,
     *     primary_stack: list<string>,
     *     has_open_roles: ?bool,
     *     last_checked: ?string,
     *     domain: ?string,
     *     q: ?string,
     *     recently_funded: ?bool
     * }
     */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier,
            'status' => $this->status,
            'ats_type' => $this->atsType,
            'stage' => $this->stage,
            'size' => $this->size,
            'remote_policy' => $this->remotePolicy,
            'primary_stack' => $this->primaryStack,
            'has_open_roles' => $this->hasOpenRoles,
            'last_checked' => $this->lastChecked,
            'domain' => $this->domain,
            'q' => $this->q,
            'graveyard' => $this->graveyard,
            'recently_funded' => $this->recentlyFunded,
        ];
    }

    /**
     * Accept null | string | list<mixed>. CSV strings are split. If a
     * whitelist is supplied, values outside it are dropped.
     *
     * @param  list<string>|null  $whitelist
     * @return list<string>
     */
    private static function filterMulti(mixed $value, ?array $whitelist): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value)
            ? $value
            : explode(',', (string) $value);

        $clean = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s === '') {
                continue;
            }
            if ($whitelist !== null && ! in_array($s, $whitelist, true)) {
                continue;
            }
            $clean[] = $s;
        }

        return array_values(array_unique($clean));
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

    private function applyLastCheckedRange(Builder $query): void
    {
        if ($this->lastChecked === null) {
            return;
        }

        $now = CarbonImmutable::now();

        match ($this->lastChecked) {
            'never' => $query->whereNull('last_checked_at'),
            'last_24h' => $query->where('last_checked_at', '>=', $now->subDay()),
            'last_7d' => $query->where('last_checked_at', '>=', $now->subDays(7)),
            'last_30d' => $query->where('last_checked_at', '>=', $now->subDays(30)),
            'older_than_30d' => $query->where('last_checked_at', '<', $now->subDays(30)),
            default => null,
        };
    }
}
