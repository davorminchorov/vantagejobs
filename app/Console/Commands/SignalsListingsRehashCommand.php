<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\Event;
use App\Scrapers\Aggregator\RoleHasher;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Backfill role_hash on existing aggregator_listings and emit historical
 * boomerang Events. Idempotent: a listing whose role_hash is already
 * stored is left alone (unless --force is passed), and Events are
 * de-duplicated by (company_id, role_hash) before insert.
 *
 * Phase 4 step 5. Run once after migrating to populate the column for
 * the ~584 existing listings; from then on the persister keeps it in
 * sync inline.
 */
final class SignalsListingsRehashCommand extends Command
{
    private const BOOMERANG_GAP_DAYS = 14;

    protected $signature = 'signals:listings:rehash
        {--tenant= : Tenant slug}
        {--force : Recompute role_hash even when already set}';

    protected $description = 'Backfill role_hash on aggregator_listings and emit historical boomerang Events.';

    public function __construct(private readonly RoleHasher $hasher)
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
        $force = (bool) $this->option('force');

        $hashed = $this->backfillHashes($force);
        $boomerangs = $this->emitHistoricalBoomerangs();

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:listings:rehash',
            'tenant' => $tenant->slug,
            'hashed' => $hashed,
            'boomerangs_emitted' => $boomerangs,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function backfillHashes(bool $force): int
    {
        $query = AggregatorListing::query();
        if (! $force) {
            $query->whereNull('role_hash');
        }

        $count = 0;
        $query->chunkById(500, function ($chunk) use (&$count): void {
            foreach ($chunk as $listing) {
                $hash = $this->hasher->hash(
                    $listing->normalized_company_key,
                    $listing->normalized_role_key,
                    $listing->location_bucket,
                );
                $listing->role_hash = $hash;
                $listing->save();
                $count++;
            }
        });

        return $count;
    }

    /**
     * For every (company_id, role_hash) group with a matched company,
     * find ordered fetched_at timestamps; whenever the gap between
     * consecutive sightings exceeds 14 days, ensure a boomerang Event
     * exists at the later sighting.
     */
    private function emitHistoricalBoomerangs(): int
    {
        $rows = AggregatorListing::query()
            ->whereNotNull('matched_company_id')
            ->whereNotNull('role_hash')
            ->whereNotIn('status', [
                AggregatorListingStatus::DUPLICATE->value,
                AggregatorListingStatus::AUTO_DISMISSED->value,
            ])
            ->orderBy('matched_company_id')
            ->orderBy('role_hash')
            ->orderBy('fetched_at')
            ->get(['id', 'matched_company_id', 'role_hash', 'fetched_at']);

        $emitted = 0;
        $previousByGroup = [];
        foreach ($rows as $row) {
            $key = $row->matched_company_id.'|'.$row->role_hash;
            $prior = $previousByGroup[$key] ?? null;
            $previousByGroup[$key] = $row;

            if ($prior === null) {
                continue;
            }

            $gapDays = $prior->fetched_at->diffInDays($row->fetched_at);
            if ($gapDays < self::BOOMERANG_GAP_DAYS) {
                continue;
            }

            $alreadyLogged = Event::query()
                ->where('company_id', $row->matched_company_id)
                ->where('type', EventType::BOOMERANG->value)
                ->where('body', 'like', '%"aggregator_listing_id":'.$row->id.'%')
                ->exists();

            if ($alreadyLogged) {
                continue;
            }

            Event::query()->create([
                'company_id' => $row->matched_company_id,
                'role_id' => null,
                'type' => EventType::BOOMERANG->value,
                'title' => 'Role re-posted after '.((int) $gapDays).' days',
                'body' => (string) json_encode([
                    'aggregator_listing_id' => $row->id,
                    'role_hash' => $row->role_hash,
                    'previously_seen_at' => $prior->fetched_at->toIso8601String(),
                ]),
                'occurred_at' => $row->fetched_at,
            ]);
            $emitted++;
        }

        return $emitted;
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
