<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Diffs fetched-vs-existing roles for one (company, source) pair, then
 * applies the resulting plan inside a transaction. Emits Event rows for
 * new / closed / reopened roles. Touches (still-open seen again) refresh
 * the mutable fields (title, comp, location, ...) plus last_seen_at — no
 * event; detail changes are silent by design.
 *
 * Idempotency contract: applying twice against the same fetched list
 * produces zero new Events on the second call.
 */
final class RoleDiffer
{
    /**
     * Compute the diff plan without touching the database.
     *
     * @param  list<ParsedRole>  $parsed
     */
    public function plan(Collection $existing, array $parsed): RoleDiffPlan
    {
        $existingByExternalId = $existing->keyBy('external_id');
        $parsedByExternalId = [];
        foreach ($parsed as $row) {
            $parsedByExternalId[$row->externalId] = $row;
        }

        $toInsert = [];
        $toReopen = [];
        $toTouch = [];

        foreach ($parsedByExternalId as $externalId => $row) {
            /** @var Role|null $current */
            $current = $existingByExternalId->get($externalId);

            if ($current === null) {
                $toInsert[] = $row;

                continue;
            }

            if (! $current->is_open) {
                $toReopen[] = $current->id;
            }

            $toTouch[] = ['id' => $current->id, 'parsed' => $row];
        }

        $toClose = [];
        foreach ($existingByExternalId as $externalId => $role) {
            /** @var Role $role */
            if ($role->is_open && ! isset($parsedByExternalId[$externalId])) {
                $toClose[] = $role->id;
            }
        }

        return new RoleDiffPlan($toInsert, $toReopen, $toClose, $toTouch);
    }

    /**
     * Guards against applying a diff that would close every open role for a
     * source on the strength of an empty fetch alone. A truly empty board
     * closes zero currently-open roles anyway (there's nothing to close),
     * so this only fires when the fetch would otherwise wipe out roles that
     * were open a moment ago — the signature of a malformed/truncated
     * response rather than a genuinely empty board. Callers should surface
     * this as a run error instead of calling {@see apply()}.
     *
     * @param  list<ParsedRole>  $parsed
     */
    public function wouldMassClose(Collection $existing, array $parsed): bool
    {
        if ($parsed !== []) {
            return false;
        }

        return $existing->contains(fn (Role $role): bool => $role->is_open);
    }

    /**
     * Apply a plan: insert/reopen/close/touch Role rows and emit Events.
     *
     * Returns the counts written, suitable for ScraperRunResult.
     *
     * @return array{new: int, reopened: int, closed: int, touched: int}
     */
    public function apply(Company $company, AtsType $source, RoleDiffPlan $plan): array
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($company, $source, $plan, $now): array {
            foreach ($plan->toInsert as $parsed) {
                $role = $this->insertRole($company, $source, $parsed, $now);

                Event::query()->create([
                    'company_id' => $company->id,
                    'role_id' => $role->id,
                    'type' => EventType::NEW_ROLE->value,
                    'title' => "New role: {$role->title}",
                    'body' => null,
                    'occurred_at' => $now,
                ]);
            }

            foreach ($plan->toReopen as $roleId) {
                Role::query()->whereKey($roleId)->update([
                    'is_open' => true,
                    'last_seen_at' => $now,
                ]);

                Event::query()->create([
                    'company_id' => $company->id,
                    'role_id' => $roleId,
                    'type' => EventType::ROLE_REOPENED->value,
                    'title' => 'Role reopened',
                    'body' => null,
                    'occurred_at' => $now,
                ]);
            }

            foreach ($plan->toClose as $roleId) {
                Role::query()->whereKey($roleId)->update([
                    'is_open' => false,
                ]);

                Event::query()->create([
                    'company_id' => $company->id,
                    'role_id' => $roleId,
                    'type' => EventType::ROLE_CLOSED->value,
                    'title' => 'Role closed',
                    'body' => null,
                    'occurred_at' => $now,
                ]);
            }

            foreach ($plan->toTouch as $touch) {
                /** @var ParsedRole $parsed */
                $parsed = $touch['parsed'];

                // Instance-level update (not a query-builder mass update) so
                // the array/enum casts on `raw` and `workplace_type` actually
                // serialize correctly.
                Role::query()->whereKey($touch['id'])->first()?->update([
                    'title' => $parsed->title,
                    'url' => $parsed->url,
                    'location' => $parsed->location,
                    'is_remote' => $parsed->isRemote,
                    'workplace_type' => $parsed->workplaceType->value,
                    'department' => $parsed->department,
                    'team' => $parsed->team,
                    'comp_min' => $parsed->compMin,
                    'comp_max' => $parsed->compMax,
                    'comp_currency' => $parsed->compCurrency,
                    'comp_interval' => $parsed->compInterval,
                    'raw' => $parsed->raw,
                    'posted_at' => $parsed->postedAt,
                    'last_seen_at' => $now,
                ]);
            }

            return [
                'new' => count($plan->toInsert),
                'reopened' => count($plan->toReopen),
                'closed' => count($plan->toClose),
                'touched' => count($plan->toTouch),
            ];
        });
    }

    private function insertRole(Company $company, AtsType $source, ParsedRole $parsed, CarbonImmutable $now): Role
    {
        return Role::query()->create([
            'company_id' => $company->id,
            'source' => $source->value,
            'external_id' => $parsed->externalId,
            'title' => $parsed->title,
            'url' => $parsed->url,
            'location' => $parsed->location,
            'is_remote' => $parsed->isRemote,
            'is_open' => true,
            'workplace_type' => $parsed->workplaceType->value,
            'department' => $parsed->department,
            'team' => $parsed->team,
            'comp_min' => $parsed->compMin,
            'comp_max' => $parsed->compMax,
            'comp_currency' => $parsed->compCurrency,
            'comp_interval' => $parsed->compInterval,
            'raw' => $parsed->raw,
            'posted_at' => $parsed->postedAt,
            'last_seen_at' => $now,
        ]);
    }
}
