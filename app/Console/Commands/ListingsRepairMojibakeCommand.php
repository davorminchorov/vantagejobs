<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\ReparseAction;
use App\Scrapers\Support\MojibakeRepairer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * One-off backfill: repairs double-encoded UTF-8 in aggregator_listings.raw
 * for the sources known to have served it (RemoteOK, occasionally
 * Himalayas — see MojibakeRepairer's docblock) and re-parses each repaired
 * row so its denormalized fields, normalized keys, and role_hash regenerate
 * from the clean text.
 *
 * Idempotent: a row whose raw payload MojibakeRepairer leaves unchanged is
 * skipped, so re-running after new RemoteOK/Himalayas listings land only
 * touches the newly-affected rows. Not scheduled — run manually.
 *
 * Reparsing here goes through ReparseAction::reparse() directly, which does
 * not touch parser_version (only parser:reparse-all pins and bumps that,
 * against its own per-tenant target). A repaired row keeps its existing
 * parser_version, so it will look "behind" and get swept again the next
 * time parser:reparse-all runs — expected, not a problem this command needs
 * to work around. The upside is real: reparsing also means these rows pick
 * up whatever the parser's current discipline/region extraction produces,
 * not just clean text.
 *
 * Runs per tenant (BelongsToTenant scopes AggregatorListing). Emits a
 * single JSON line on stdout: {tenants_swept, checked, repaired}.
 */
final class ListingsRepairMojibakeCommand extends Command
{
    protected $signature = 'listings:repair-mojibake
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Repair double-encoded UTF-8 in RemoteOK/Himalayas aggregator_listings and re-parse the affected rows.';

    private const AFFECTED_SOURCE_TYPES = [
        AggregatorSourceType::REMOTEOK,
        AggregatorSourceType::HIMALAYAS,
    ];

    public function __construct(private readonly ReparseAction $reparseAction)
    {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants === null) {
            return self::FAILURE;
        }

        $checked = 0;
        $repaired = 0;

        foreach ($tenants as $tenant) {
            [$tenantChecked, $tenantRepaired] = $context->withTenant(
                $tenant,
                fn (): array => $this->repairForTenant(),
            );
            $checked += $tenantChecked;
            $repaired += $tenantRepaired;
        }

        $this->line((string) json_encode([
            'command' => 'listings:repair-mojibake',
            'tenants_swept' => $tenants->count(),
            'checked' => $checked,
            'repaired' => $repaired,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [checked, repaired]
     */
    private function repairForTenant(): array
    {
        $sourceIds = AggregatorSource::query()
            ->whereIn('type', array_map(
                static fn (AggregatorSourceType $type): string => $type->value,
                self::AFFECTED_SOURCE_TYPES,
            ))
            ->pluck('id')
            ->all();

        if ($sourceIds === []) {
            return [0, 0];
        }

        $checked = 0;
        $repaired = 0;

        AggregatorListing::query()
            ->whereIn('source_id', $sourceIds)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$checked, &$repaired): void {
                foreach ($rows as $listing) {
                    $checked++;

                    $raw = is_array($listing->raw) ? $listing->raw : [];
                    $fixedRaw = MojibakeRepairer::repairArray($raw);

                    if ($fixedRaw === $raw) {
                        continue;
                    }

                    $listing->raw = $fixedRaw;
                    $listing->save();
                    $this->reparseAction->reparse($listing);
                    $repaired++;
                }
            });

        return [$checked, $repaired];
    }

    /**
     * @return Collection<int, Tenant>|null
     */
    private function resolveTenants(): ?Collection
    {
        $slug = $this->option('tenant');

        if (is_string($slug) && $slug !== '') {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                $this->error("Tenant [{$slug}] not found.");

                return null;
            }

            return new Collection([$tenant]);
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
