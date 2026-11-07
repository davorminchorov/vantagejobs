<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\WorkplaceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Role;
use Carbon\CarbonImmutable;

/**
 * Builds a real Role row from an aggregator listing's parsed fields,
 * namespaced 'aggregator:{type}' so it's disjoint from every AtsType value
 * and selectable via `source like 'aggregator:%'`. Returns null when
 * parsed_role_title is null, since a Role needs a title.
 *
 * Nothing else ever re-fetches this listing's source for this job, so two
 * close paths exist for it: RoleDiffer::apply() supersedes it silently once
 * the real ATS ingests the same title, and roles:close-stale-aggregator
 * closes it if last_seen_at goes stale.
 */
final readonly class RoleFromListing
{
    public function create(AggregatorSource $source, Company $company, AggregatorListing $listing): ?Role
    {
        $title = $listing->parsed_role_title;
        if ($title === null) {
            return null;
        }

        $isRemote = $listing->parsed_remote_type === 'remote';
        $hasComp = $listing->parsed_comp_min_usd !== null || $listing->parsed_comp_max_usd !== null;

        return Role::query()->create([
            'company_id' => $company->id,
            'source' => 'aggregator:'.$source->type->value,
            'external_id' => $listing->external_id,
            'aggregator_listing_id' => $listing->id,
            'title' => $title,
            'url' => $listing->url,
            'location' => $listing->location_bucket,
            'is_remote' => $isRemote,
            'is_open' => true,
            'workplace_type' => $isRemote ? WorkplaceType::REMOTE->value : WorkplaceType::UNSPECIFIED->value,
            'comp_min' => $listing->parsed_comp_min_usd,
            'comp_max' => $listing->parsed_comp_max_usd,
            'comp_currency' => $hasComp ? 'USD' : null,
            'raw' => $listing->parsed,
            'posted_at' => $listing->posted_at,
            'last_seen_at' => CarbonImmutable::now(),
        ]);
    }
}
