<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Bookmark;
use App\Models\Company;
use App\Models\DismissedCompany;
use App\Models\Role;
use App\Models\ScrapeRun;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\AggregatorSourcesSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase2SchemaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'phase-2']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function aggregator_listing_is_in_the_morph_map(): void
    {
        $this->assertSame(AggregatorListing::class, Relation::morphMap()['aggregator_listing'] ?? null);
    }

    #[Test]
    public function aggregator_source_casts_type_and_dates(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        $this->assertSame(AggregatorSourceType::HACKERNEWS, $source->fresh()->type);
        $this->assertTrue($source->enabled);
        $this->assertSame(AggregatorSourceType::HACKERNEWS->defaultCadenceMinutes(), $source->cadence_minutes);
    }

    #[Test]
    public function aggregator_source_unique_per_tenant_and_type(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
    }

    #[Test]
    public function aggregator_listing_casts_status_jsonb_and_dates(): void
    {
        $listing = AggregatorListing::factory()->create([
            'parsed' => ['company' => ['value' => 'Acme', 'confidence' => 0.9, 'source' => 'explicit']],
        ]);

        $fresh = $listing->fresh();
        $this->assertSame(AggregatorListingStatus::NEW, $fresh->status);
        $this->assertSame('Acme', $fresh->parsed['company']['value']);
        $this->assertIsArray($fresh->stack_tags);
    }

    #[Test]
    public function aggregator_listing_unique_per_source_and_external_id(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'hn-12345',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'hn-12345',
        ]);
    }

    #[Test]
    public function aggregator_listing_can_match_a_tracked_company(): void
    {
        $company = Company::factory()->create();
        $listing = AggregatorListing::factory()->create([
            'matched_company_id' => $company->id,
            'status' => AggregatorListingStatus::LINKED,
        ]);

        $this->assertSame($company->id, $listing->fresh()->matchedCompany?->id);
    }

    #[Test]
    public function dismissed_company_auto_flag_casts(): void
    {
        $row = DismissedCompany::factory()->auto()->create();

        $this->assertTrue($row->fresh()->auto_dismiss);
        $this->assertSame(3, $row->fresh()->dismiss_count);
    }

    #[Test]
    public function dismissed_company_unique_per_normalized_key(): void
    {
        DismissedCompany::factory()->create(['normalized_company_key' => 'acmecorp']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DismissedCompany::factory()->create(['normalized_company_key' => 'acmecorp']);
    }

    #[Test]
    public function bookmark_on_aggregator_listing_writes_short_morph_key(): void
    {
        $listing = AggregatorListing::factory()->create();

        $listing->bookmarks()->create([
            'tenant_id' => $this->tenant->id,
            'expires_at' => now()->addDays(30),
        ]);

        $row = DB::table('bookmarks')->sole();
        $this->assertSame('aggregator_listing', $row->bookmarkable_type);
        $this->assertSame($listing->id, (int) $row->bookmarkable_id);
    }

    #[Test]
    public function bookmark_on_role_uses_short_morph_key(): void
    {
        $role = Role::factory()->create(['company_id' => Company::factory()]);

        Bookmark::factory()->create([
            'bookmarkable_type' => 'role',
            'bookmarkable_id' => $role->id,
        ]);

        $this->assertSame('role', DB::table('bookmarks')->value('bookmarkable_type'));
    }

    #[Test]
    public function bookmark_unique_per_target(): void
    {
        $listing = AggregatorListing::factory()->create();

        Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listing->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listing->id,
        ]);
    }

    #[Test]
    public function scrape_runs_source_kind_defaults_to_ats(): void
    {
        $run = ScrapeRun::factory()->create([
            'company_id' => Company::factory(),
        ]);

        $this->assertSame('ats', $run->fresh()->source_kind);
    }

    #[Test]
    public function scrape_runs_accepts_aggregator_source_kind_without_company(): void
    {
        $run = ScrapeRun::factory()->create([
            'company_id' => null,
            'source' => 'hn',
            'source_kind' => 'aggregator',
        ]);

        $this->assertSame('aggregator', $run->fresh()->source_kind);
        $this->assertNull($run->fresh()->company_id);
    }

    #[Test]
    public function aggregator_source_auto_fills_tenant_id(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::WEWORKREMOTELY)->create();

        $this->assertSame($this->tenant->id, $source->tenant_id);
    }

    #[Test]
    public function seeder_creates_one_source_per_enum_case_idempotently(): void
    {
        $this->seed(AggregatorSourcesSeeder::class);
        $this->seed(AggregatorSourcesSeeder::class);

        $this->assertSame(count(AggregatorSourceType::cases()), AggregatorSource::query()->count());

        foreach (AggregatorSourceType::cases() as $type) {
            $this->assertTrue(
                AggregatorSource::query()->where('type', $type->value)->exists(),
                "Missing aggregator source for {$type->value}",
            );
        }
    }
}
