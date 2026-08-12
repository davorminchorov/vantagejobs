<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\DismissedCompany;
use App\Models\Event;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Scrapers\Aggregator\RoleHasher;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Backfill role_hash on existing aggregator_listings and emit historical
 * boomerang Events. Idempotent: a listing whose role_hash is already
 * stored is left alone (unless --force is passed), and Events are
 * de-duplicated by (company_id, role_hash) before insert.
 *
 * Phase 4 step 5. Run once after migrating to populate the column for
 * the ~584 existing listings; from then on the persister keeps it in
 * sync inline.
 *
 * --renormalize is a separate, one-off pass for when CompanyKeyNormalizer's
 * normalization rules change (e.g. the switch to unicode-aware stripping):
 * it recomputes normalized_company_key/normalized_role_key from the stored
 * parsed_company_name/parsed_role_title on every aggregator_listings row
 * (not just the unhashed ones --force targets), re-derives role_hash from
 * the fresh keys, and also re-normalizes dismissed_companies.
 * normalized_company_key from display_name. Latin-only names normalize
 * identically under the old and new rules, so this is a safety net for the
 * handful of affected non-Latin rows, not a mass rewrite.
 */
final class SignalsListingsRehashCommand extends Command
{
    private const BOOMERANG_GAP_DAYS = 14;

    protected $signature = 'signals:listings:rehash
        {--tenant= : Tenant slug}
        {--force : Recompute role_hash even when already set}
        {--renormalize : Recompute normalized_company_key/normalized_role_key from stored parsed fields on every listing, re-hash, and re-normalize dismissed_companies (run once after a CompanyKeyNormalizer change)}';

    protected $description = 'Backfill role_hash on aggregator_listings and emit historical boomerang Events.';

    public function __construct(
        private readonly RoleHasher $hasher,
        private readonly CompanyKeyNormalizer $normalizer,
    ) {
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
        $renormalize = (bool) $this->option('renormalize');

        if ($renormalize) {
            $hashed = $this->renormalizeListings();
            $dismissedRenormalized = $this->renormalizeDismissedCompanies();
        } else {
            $hashed = $this->backfillHashes($force);
            $dismissedRenormalized = 0;
        }

        $boomerangs = $this->emitHistoricalBoomerangs();

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:listings:rehash',
            'tenant' => $tenant->slug,
            'hashed' => $hashed,
            'dismissed_companies_renormalized' => $dismissedRenormalized,
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
     * Recompute normalized_company_key/normalized_role_key from the stored
     * parsed_company_name/parsed_role_title on every listing, then re-derive
     * role_hash from the fresh keys. Unlike backfillHashes(), this always
     * touches every row regardless of whether role_hash was already set.
     */
    private function renormalizeListings(): int
    {
        $count = 0;
        AggregatorListing::query()->chunkById(500, function ($chunk) use (&$count): void {
            foreach ($chunk as $listing) {
                $companyKey = $this->normalizer->normalize($listing->parsed_company_name);
                $roleKey = $this->normalizer->normalize($listing->parsed_role_title);

                $listing->normalized_company_key = $companyKey;
                $listing->normalized_role_key = $roleKey;
                $listing->role_hash = $this->hasher->hash($companyKey, $roleKey, $listing->location_bucket);
                $listing->save();
                $count++;
            }
        });

        return $count;
    }

    /**
     * DismissedCompany.normalized_company_key is looked up by the
     * auto-dismiss gate on every ingest; it needs the same renormalization
     * pass as aggregator_listings. normalized_company_key carries a
     * (tenant_id, normalized_company_key) unique constraint, so two
     * distinct rows whose display names newly collapse to the same key
     * under the updated rules would conflict on save — skip those and let
     * the operator merge them by hand rather than crashing the sweep.
     */
    private function renormalizeDismissedCompanies(): int
    {
        $count = 0;
        DismissedCompany::query()->chunkById(500, function ($chunk) use (&$count): void {
            foreach ($chunk as $dismissed) {
                $dismissed->normalized_company_key = $this->normalizer->normalize($dismissed->display_name);
                try {
                    $dismissed->save();
                    $count++;
                } catch (QueryException $e) {
                    $this->warn("Skipped dismissed_companies id={$dismissed->id}: {$e->getMessage()}");
                }
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
