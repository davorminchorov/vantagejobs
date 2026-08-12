<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Hard-deletes DISMISSED AggregatorListing rows past the tenant's configured
 * retention window (PRD §6.4.9: 90 days, settings-configurable via
 * dismissed_retention_days — see {@see SettingsRepository}).
 *
 * There is no dedicated "dismissed_at" column, so `updated_at` is used as the
 * cutoff: DismissListingAction's write is the terminal mutation on a listing's
 * lifecycle, so updated_at reflects the moment it was dismissed.
 *
 * AUTO_DISMISSED rows are left alone — they're the audit trail behind an
 * auto-dismiss rule (see DismissedCompany), not the manual triage queue this
 * sweep is clearing out.
 *
 * duplicate_of_id is a nullOnDelete foreign key (see the aggregator_listings
 * migration), so deleting a row that another listing points to is handled at
 * the database level — no manual cleanup needed here.
 *
 * Runs per tenant so each tenant's own retention setting applies. Emits a
 * single JSON line on stdout: {tenants_swept, deleted}.
 */
final class ListingsPruneDismissedCommand extends Command
{
    protected $signature = 'listings:prune-dismissed
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Hard-delete DISMISSED aggregator listings past the configured retention window.';

    private const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(private readonly SettingsRepository $settings)
    {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants === null) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $totalDeleted = 0;

        foreach ($tenants as $tenant) {
            $totalDeleted += $context->withTenant(
                $tenant,
                fn (): int => $this->pruneForTenant($now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'listings:prune-dismissed',
            'tenants_swept' => $tenants->count(),
            'deleted' => $totalDeleted,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function pruneForTenant(CarbonImmutable $now): int
    {
        $retentionDays = (int) $this->settings->get(
            SettingKey::DISMISSED_RETENTION_DAYS,
            self::DEFAULT_RETENTION_DAYS,
        );

        $cutoff = $now->subDays(max(1, $retentionDays));

        $deleted = 0;

        AggregatorListing::query()
            ->where('status', AggregatorListingStatus::DISMISSED->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$deleted): void {
                foreach ($rows as $row) {
                    $row->delete();
                    $deleted++;
                }
            });

        return $deleted;
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
