<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Models\AggregatorListing;
use App\Models\ParseCorrection;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase3SchemaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'phase-3']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function parser_version_defaults_to_one(): void
    {
        $listing = AggregatorListing::factory()->create();

        $this->assertSame(1, $listing->fresh()->parser_version);
    }

    #[Test]
    public function widened_columns_accept_long_strings(): void
    {
        $long = str_repeat('a very long fragment of role title text ', 30);

        $listing = AggregatorListing::factory()->create([
            'parsed_role_title' => $long,
            'parsed_company_name' => $long,
            'normalized_role_key' => $long,
            'normalized_company_key' => $long,
            'location_bucket' => $long,
        ]);

        $this->assertSame($long, $listing->fresh()->parsed_role_title);
    }

    #[Test]
    public function parse_correction_belongs_to_listing_and_user(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $listing = AggregatorListing::factory()->create();

        $correction = ParseCorrection::factory()->create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'field' => 'company_name',
            'old_value' => ['value' => 'Acme'],
            'old_confidence' => 0.5,
            'old_source' => 'inferred',
            'new_value' => ['value' => 'Acme Inc.'],
        ]);

        $this->assertSame($listing->id, $correction->listing->id);
        $this->assertSame($user->id, $correction->user->id);
        $this->assertEquals(['value' => 'Acme'], $correction->old_value);
        $this->assertEquals(['value' => 'Acme Inc.'], $correction->new_value);
        $this->assertEqualsWithDelta(0.5, $correction->old_confidence, 0.0001);
    }

    #[Test]
    public function aggregator_listing_has_many_corrections(): void
    {
        $listing = AggregatorListing::factory()->create();

        $this->assertInstanceOf(HasMany::class, $listing->corrections());

        ParseCorrection::factory()->count(3)->create(['listing_id' => $listing->id]);

        $this->assertCount(3, $listing->corrections);
    }

    #[Test]
    public function parse_correction_cascades_on_listing_delete(): void
    {
        $listing = AggregatorListing::factory()->create();
        ParseCorrection::factory()->count(2)->create(['listing_id' => $listing->id]);

        $listing->delete();

        $this->assertSame(0, DB::table('parse_corrections')->where('listing_id', $listing->id)->count());
    }

    #[Test]
    public function parse_correction_is_scoped_to_tenant(): void
    {
        $other = Tenant::factory()->create(['slug' => 'phase-3-other']);

        // Create one correction for current tenant.
        $listing = AggregatorListing::factory()->create();
        ParseCorrection::factory()->create(['listing_id' => $listing->id]);

        // Switch tenants and verify scope.
        app(TenantContext::class)->setCurrent($other);

        $this->assertSame(0, ParseCorrection::query()->count());

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(1, ParseCorrection::query()->count());
    }
}
