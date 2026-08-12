<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\Role;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Closes open, aggregator-born Roles (source like 'aggregator:%', see
 * AggregatorListingPersister::attemptAutoLink()) that haven't been re-seen
 * in a while.
 *
 * Nothing else re-fetches these once they're created: unlike an ATS role,
 * there's no source of truth that confirms the posting closed. Two signals
 * keep last_seen_at fresh in the meantime — the listing's own source
 * re-fetching it (AggregatorListingPersister::touchLinkedRole()) and the
 * real ATS ingesting the same title (RoleDiffer::supersedeAggregatorRole(),
 * which closes it immediately instead of waiting for this sweep). This
 * command is the backstop for roles that get neither: the aggregator drops
 * the listing (or the run stops re-fetching it) and the company never adds
 * an ATS, so last_seen_at simply goes stale.
 *
 * Retention is config('scrapers.aggregator_role_stale_days') (env
 * SCRAPER_AGGREGATOR_ROLE_STALE_DAYS, default 21), measured from
 * last_seen_at. Emits a ROLE_CLOSED Event per closed Role — unlike the
 * silent RoleDiffer supersede path, there's no "new role" Event immediately
 * after to make the closure implicit, so the timeline needs its own entry.
 *
 * Runs per tenant (BelongsToTenant scoping + Event creation both require an
 * active TenantContext). Emits a single JSON line on stdout:
 * {tenants_swept, closed}.
 */
final class RolesCloseStaleAggregatorCommand extends Command
{
    protected $signature = 'roles:close-stale-aggregator
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Close open aggregator-born roles that have gone stale (no re-sighting within the retention window).';

    public function handle(TenantContext $context): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants === null) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $totalClosed = 0;

        foreach ($tenants as $tenant) {
            $totalClosed += $context->withTenant(
                $tenant,
                fn (): int => $this->closeStaleForTenant($now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'roles:close-stale-aggregator',
            'tenants_swept' => $tenants->count(),
            'closed' => $totalClosed,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function closeStaleForTenant(CarbonImmutable $now): int
    {
        $staleDays = (int) config('scrapers.aggregator_role_stale_days');
        $cutoff = $now->subDays(max(1, $staleDays));

        $closed = 0;

        Role::query()
            ->where('source', 'like', 'aggregator:%')
            ->where('is_open', true)
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_seen_at', '<', $cutoff)
                    ->orWhereNull('last_seen_at');
            })
            ->orderBy('id')
            ->chunkById(500, function (Collection $roles) use (&$closed, $now): void {
                foreach ($roles as $role) {
                    /** @var Role $role */
                    $role->update(['is_open' => false]);

                    Event::query()->create([
                        'company_id' => $role->company_id,
                        'role_id' => $role->id,
                        'type' => EventType::ROLE_CLOSED->value,
                        'title' => 'Role closed (stale aggregator listing)',
                        'body' => null,
                        'occurred_at' => $now,
                    ]);

                    $closed++;
                }
            });

        return $closed;
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
