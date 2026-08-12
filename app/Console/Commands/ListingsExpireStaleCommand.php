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
 * Moves stale NEW AggregatorListing rows to EXPIRED so the triage queue only
 * holds postings still worth a decision. A posting older than the tenant's
 * configured window (listing_expiry_days, default 30) is almost certainly
 * filled; leaving it in NEW wastes triage keystrokes and inflates the count.
 *
 * Age is judged by posted_at, falling back to created_at (first seen) for
 * undated listings — otherwise undated rows would never expire.
 *
 * Only NEW listings expire. Kept, bookmarked, and snoozed rows encode user
 * intent and are never touched; snoozed listings have their own wake sweep.
 * EXPIRED rows stay in the database (they feed comp insights and quality
 * signals), remain visible under the Expired tab on /discover, and can be
 * rescued via Keep. The dismissed-retention prune deliberately ignores them.
 *
 * Runs per tenant so each tenant's own expiry setting applies. Emits a single
 * JSON line on stdout, matching the listings:prune-dismissed style:
 * {tenants_swept, expired}.
 */
final class ListingsExpireStaleCommand extends Command
{
    protected $signature = 'listings:expire-stale
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Move NEW aggregator listings past the configured expiry window to EXPIRED.';

    private const DEFAULT_EXPIRY_DAYS = 30;

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
        $totalExpired = 0;

        foreach ($tenants as $tenant) {
            $totalExpired += $context->withTenant(
                $tenant,
                fn (): int => $this->expireForTenant($now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'listings:expire-stale',
            'tenants_swept' => $tenants->count(),
            'expired' => $totalExpired,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function expireForTenant(CarbonImmutable $now): int
    {
        $expiryDays = (int) $this->settings->get(
            SettingKey::LISTING_EXPIRY_DAYS,
            self::DEFAULT_EXPIRY_DAYS,
        );

        $cutoff = $now->subDays(max(1, $expiryDays));

        return AggregatorListing::query()
            ->where('status', AggregatorListingStatus::NEW->value)
            ->whereRaw('COALESCE(posted_at, created_at) < ?', [$cutoff])
            ->update(['status' => AggregatorListingStatus::EXPIRED->value]);
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
