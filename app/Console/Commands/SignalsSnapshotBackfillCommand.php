<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventType;
use App\Models\Company;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Rebuilds past company_snapshots rows from stored role history, so hiring
 * trends have data before the daily snapshot job started running.
 *
 * Open-role count at the end of each UTC day is replayed per role:
 *  - a role opens when it was first seen (`roles.created_at`);
 *  - `role_closed` / `role_reopened` events toggle it after that;
 *  - a closed role with no close event closes at `last_seen_at`.
 * Counts only change on days a scrape ran, so series step between scrapes.
 *
 * Fields without history: `tier` and `status` take the company's current
 * values; `listings_count` counts listings matched to the company that were
 * created by that day and are still new/kept/linked (removals are not
 * replayed). Signal columns (quality, github, blog, layoffs) stay empty.
 *
 * Never writes a day before the company was added, never writes today (the
 * daily job owns it), and never overwrites an existing snapshot row.
 */
final class SignalsSnapshotBackfillCommand extends Command
{
    private const INSERT_CHUNK = 1_000;

    protected $signature = 'signals:snapshot:backfill
        {--tenant= : Tenant slug}
        {--since= : First ISO date to fill (default: the day the first role was seen)}';

    protected $description = 'Rebuild past daily company_snapshots rows from role history without touching existing rows.';

    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }
        $context->setCurrent($tenant);

        $startedAt = CarbonImmutable::now();
        $until = CarbonImmutable::now('UTC')->startOfDay()->subDay();

        $since = $this->resolveSince($tenant);
        if ($since === false) {
            return self::FAILURE;
        }
        if ($since === null || $since->greaterThan($until)) {
            $this->line((string) json_encode(['command' => 'signals:snapshot:backfill', 'tenant' => $tenant->slug, 'rows_written' => 0], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $roleDeltas = $this->roleDeltas($tenant->id);
        $listingDeltas = $this->listingDeltas($tenant->id);

        $companies = Company::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'tier', 'status', 'created_at']);

        $now = CarbonImmutable::now();
        $rows = [];
        $written = 0;

        foreach ($companies as $company) {
            $addedOn = self::day($company->created_at);
            $first = $addedOn !== null && $addedOn > $since->toDateString() ? $addedOn : $since->toDateString();

            $openSeries = self::cumulative($roleDeltas[$company->id] ?? [], $first, $until);
            $listingSeries = self::cumulative($listingDeltas[$company->id] ?? [], $first, $until);

            foreach ($openSeries as $date => $openCount) {
                $rows[] = [
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'snapshot_date' => $date,
                    'open_role_count' => max(0, $openCount),
                    'listings_count' => max(0, $listingSeries[$date] ?? 0),
                    'tier' => $company->tier,
                    'status' => $company->status,
                    'github_score' => null,
                    'blog_posts_90d' => null,
                    'has_recent_layoff_event' => false,
                    'quality_score' => null,
                    'breakdown' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) >= self::INSERT_CHUNK) {
                    $written += $this->db->table('company_snapshots')->insertOrIgnore($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            $written += $this->db->table('company_snapshots')->insertOrIgnore($rows);
        }

        $this->line((string) json_encode([
            'command' => 'signals:snapshot:backfill',
            'tenant' => $tenant->slug,
            'since' => $since->toDateString(),
            'until' => $until->toDateString(),
            'companies' => $companies->count(),
            'rows_written' => $written,
            'duration_ms' => CarbonImmutable::now()->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Per company, per UTC day: roles that opened minus roles that closed.
     *
     * @return array<int, array<string, int>>
     */
    private function roleDeltas(int $tenantId): array
    {
        $toggles = [];
        $this->db->table('events')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('role_id')
            ->whereIn('type', [EventType::ROLE_CLOSED->value, EventType::ROLE_REOPENED->value])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['role_id', 'type', 'occurred_at'])
            ->each(function (object $event) use (&$toggles): void {
                $toggles[(int) $event->role_id][] = [(string) $event->type, (string) $event->occurred_at];
            });

        $deltas = [];
        $this->db->table('roles')
            ->where('tenant_id', $tenantId)
            ->get(['id', 'company_id', 'created_at', 'is_open', 'last_seen_at', 'updated_at'])
            ->each(function (object $role) use (&$deltas, $toggles): void {
                $companyId = (int) $role->company_id;
                $openedAt = (string) $role->created_at;
                $isOpen = true;
                self::bump($deltas, $companyId, $openedAt, 1);

                foreach ($toggles[(int) $role->id] ?? [] as [$type, $at]) {
                    if ($at < $openedAt) {
                        continue;
                    }
                    if ($type === EventType::ROLE_CLOSED->value && $isOpen) {
                        $isOpen = false;
                        self::bump($deltas, $companyId, $at, -1);
                    } elseif ($type === EventType::ROLE_REOPENED->value && ! $isOpen) {
                        $isOpen = true;
                        self::bump($deltas, $companyId, $at, 1);
                    }
                }

                if ($isOpen && ! (bool) $role->is_open) {
                    self::bump($deltas, $companyId, (string) ($role->last_seen_at ?? $role->updated_at), -1);
                }
            });

        return $deltas;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function listingDeltas(int $tenantId): array
    {
        $deltas = [];
        $this->db->table('aggregator_listings')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('matched_company_id')
            ->whereIn('status', ['new', 'kept', 'linked'])
            ->get(['matched_company_id', 'created_at'])
            ->each(function (object $listing) use (&$deltas): void {
                self::bump($deltas, (int) $listing->matched_company_id, (string) $listing->created_at, 1);
            });

        return $deltas;
    }

    /**
     * @param  array<int, array<string, int>>  $deltas
     */
    private static function bump(array &$deltas, int $companyId, string $timestamp, int $by): void
    {
        $day = self::day($timestamp);
        if ($day === null) {
            return;
        }
        $deltas[$companyId][$day] = ($deltas[$companyId][$day] ?? 0) + $by;
    }

    /**
     * Running total at the end of each day from `$first` through `$until`,
     * including every change before `$first`.
     *
     * @param  array<string, int>  $deltas
     * @return array<string, int>
     */
    private static function cumulative(array $deltas, string $first, CarbonImmutable $until): array
    {
        ksort($deltas);
        $last = $until->toDateString();

        $running = 0;
        foreach ($deltas as $day => $delta) {
            if ($day < $first) {
                $running += $delta;
            }
        }

        $series = [];
        for ($day = CarbonImmutable::parse($first, 'UTC'); $day->toDateString() <= $last; $day = $day->addDay()) {
            $running += $deltas[$day->toDateString()] ?? 0;
            $series[$day->toDateString()] = $running;
        }

        return $series;
    }

    private static function day(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return CarbonImmutable::parse($timestamp)->utc()->toDateString();
    }

    private function resolveSince(Tenant $tenant): CarbonImmutable|false|null
    {
        $raw = $this->option('since');
        if (is_string($raw) && $raw !== '') {
            try {
                return CarbonImmutable::parse($raw, 'UTC')->startOfDay();
            } catch (\Throwable) {
                $this->error("Invalid --since [{$raw}]. Expected ISO date (YYYY-MM-DD).");

                return false;
            }
        }

        $firstSeen = $this->db->table('roles')->where('tenant_id', $tenant->id)->min('created_at');

        return $firstSeen === null ? null : CarbonImmutable::parse((string) $firstSeen)->utc()->startOfDay();
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');
        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] not found.");

            return null;
        }

        return $tenant;
    }
}
