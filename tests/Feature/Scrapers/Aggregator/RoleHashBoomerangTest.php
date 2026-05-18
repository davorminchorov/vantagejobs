<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Event;
use App\Scrapers\Aggregator\RoleHasher;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RoleHashBoomerangTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function hasher_is_deterministic_and_case_insensitive(): void
    {
        $h = new RoleHasher();
        $a = $h->hash('Acme', 'Senior Backend', 'remote');
        $b = $h->hash('ACME', 'senior backend', 'REMOTE');
        $this->assertSame($a, $b);
    }

    #[Test]
    public function hasher_marks_partial_inputs_incomplete(): void
    {
        $h = new RoleHasher();
        $this->assertTrue($h->isComplete('a', 'b', 'c'));
        $this->assertFalse($h->isComplete('a', '', 'c'));
        $this->assertFalse($h->isComplete(null, 'b', 'c'));
    }

    #[Test]
    public function backfill_command_populates_role_hash_and_emits_historical_boomerangs(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $company = Company::factory()->create();

        $now = CarbonImmutable::now();
        $old = $now->subDays(30);

        // Two listings same hash, 30d apart → one boomerang
        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'r1',
            'matched_company_id' => $company->id,
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'senior backend',
            'location_bucket' => 'remote',
            'role_hash' => null,
            'status' => AggregatorListingStatus::KEPT,
            'fetched_at' => $old,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'r2',
            'matched_company_id' => $company->id,
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'senior backend',
            'location_bucket' => 'remote',
            'role_hash' => null,
            'status' => AggregatorListingStatus::KEPT,
            'fetched_at' => $now,
        ]);

        Artisan::call('signals:listings:rehash', ['--tenant' => 'primary']);

        $hashed = AggregatorListing::query()->whereNotNull('role_hash')->count();
        $this->assertSame(2, $hashed);

        $boomerangs = Event::query()
            ->where('type', EventType::BOOMERANG->value)
            ->where('company_id', $company->id)
            ->count();
        $this->assertSame(1, $boomerangs);
    }

    #[Test]
    public function backfill_skips_groups_under_14_day_gap(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $company = Company::factory()->create();
        $now = CarbonImmutable::now();

        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'r1',
            'matched_company_id' => $company->id,
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'senior backend',
            'location_bucket' => 'remote',
            'role_hash' => null,
            'status' => AggregatorListingStatus::KEPT,
            'fetched_at' => $now->subDays(5),
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'r2',
            'matched_company_id' => $company->id,
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'senior backend',
            'location_bucket' => 'remote',
            'role_hash' => null,
            'status' => AggregatorListingStatus::KEPT,
            'fetched_at' => $now,
        ]);

        Artisan::call('signals:listings:rehash', ['--tenant' => 'primary']);

        $boomerangs = Event::query()->where('type', EventType::BOOMERANG->value)->count();
        $this->assertSame(0, $boomerangs);
    }

    #[Test]
    public function backfill_is_idempotent(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $company = Company::factory()->create();
        $now = CarbonImmutable::now();

        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'r1',
            'matched_company_id' => $company->id,
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'senior backend',
            'location_bucket' => 'remote',
            'role_hash' => null,
            'status' => AggregatorListingStatus::KEPT,
            'fetched_at' => $now->subDays(30),
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'r2',
            'matched_company_id' => $company->id,
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'senior backend',
            'location_bucket' => 'remote',
            'role_hash' => null,
            'status' => AggregatorListingStatus::KEPT,
            'fetched_at' => $now,
        ]);

        Artisan::call('signals:listings:rehash', ['--tenant' => 'primary']);
        Artisan::call('signals:listings:rehash', ['--tenant' => 'primary']);

        $this->assertSame(1, Event::query()->where('type', EventType::BOOMERANG->value)->count());
    }
}
