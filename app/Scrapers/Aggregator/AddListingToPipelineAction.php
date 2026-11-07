<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Pipeline\AddRoleToPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Discover's "Add to applications": turns an aggregator listing into a role
 * at Researching in the pipeline.
 *
 * Reuses the role already created from the listing when there is one.
 * Otherwise it resolves the company (matched company, then a fuzzy name
 * match against tracked companies, then a new tier C company), links the
 * listing like the auto-link path does, creates the role and writes the
 * NEW_ROLE timeline event. Everything happens in one transaction.
 */
final readonly class AddListingToPipelineAction
{
    public const string ADDED = 'added';

    public const string ALREADY_IN_PIPELINE = 'already_in_pipeline';

    public const string MISSING_TITLE = 'missing_title';

    public const string MISSING_COMPANY = 'missing_company';

    public function __construct(
        private MatchCompanyAction $matcher,
        private RoleFromListing $roleFromListing,
        private AddRoleToPipeline $addRoleToPipeline,
    ) {}

    /**
     * @return array{outcome: string, role: Role|null, company: Company|null}
     */
    public function add(AggregatorListing $listing): array
    {
        $existing = Role::query()
            ->where('aggregator_listing_id', $listing->id)
            ->with('company')
            ->first();

        if ($existing instanceof Role) {
            $added = $this->addRoleToPipeline->add($existing, 'discover');

            return [
                'outcome' => $added ? self::ADDED : self::ALREADY_IN_PIPELINE,
                'role' => $existing,
                'company' => $existing->company,
            ];
        }

        $title = $listing->parsed_role_title;
        if ($title === null || $title === '') {
            return ['outcome' => self::MISSING_TITLE, 'role' => null, 'company' => null];
        }

        $companyName = $listing->parsed_company_name;
        $company = $listing->matched_company_id !== null
            ? Company::query()->find($listing->matched_company_id)
            : null;

        if (! $company instanceof Company && ($companyName === null || trim($companyName) === '')) {
            return ['outcome' => self::MISSING_COMPANY, 'role' => null, 'company' => null];
        }

        /** @var Role $role */
        $role = DB::transaction(function () use ($listing, $company, $companyName): Role {
            $company ??= $this->matcher->match($companyName) ?? $this->createCompany((string) $companyName);

            $listing->matched_company_id = $company->id;
            $listing->status = AggregatorListingStatus::LINKED;
            $listing->save();

            $listing->loadMissing('source');
            $role = $this->roleFromListing->create($listing->source, $company, $listing);
            if (! $role instanceof Role) {
                throw new \LogicException('Role title vanished mid-transaction.');
            }

            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => $role->id,
                'type' => EventType::NEW_ROLE->value,
                'title' => 'Seen on aggregator: '.$role->title,
                'body' => (string) json_encode([
                    'aggregator_listing_id' => $listing->id,
                    'source' => $listing->source_id,
                ]),
                'occurred_at' => $listing->posted_at ?? CarbonImmutable::now(),
            ]);

            $role->setRelation('company', $company);
            $this->addRoleToPipeline->add($role, 'discover');

            return $role;
        });

        return ['outcome' => self::ADDED, 'role' => $role, 'company' => $role->company];
    }

    /**
     * Mirrors StoreCompanyController: a tracked company plus its
     * COMPANY_ADDED event. No ATS, so nothing is scraped.
     */
    private function createCompany(string $name): Company
    {
        $company = Company::query()->create([
            'name' => trim($name),
            'tier' => 'C',
            'status' => 'researching',
        ]);

        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => null,
            'type' => EventType::COMPANY_ADDED->value,
            'title' => "Added to watch list: {$company->name}",
            'body' => null,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return $company;
    }
}
