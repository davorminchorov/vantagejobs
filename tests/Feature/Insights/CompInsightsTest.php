<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Enums\AggregatorListingStatus;
use App\Http\Controllers\Insights\CompInsightsController;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // The wiring patch will add this route to routes/web.php. Register here
        // so the controller can be exercised end-to-end without touching the
        // global route file.
        Route::middleware(['web', 'auth'])
            ->get('/insights/comp', CompInsightsController::class)
            ->name('insights.comp');
    }

    private ?AggregatorSource $source = null;

    private function listing(array $overrides = []): AggregatorListing
    {
        $this->source ??= AggregatorSource::factory()->create();

        return AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            ...$overrides,
        ]);
    }

    #[Test]
    public function buckets_role_families_by_title_heuristic_when_discipline_is_null(): void
    {
        // Legacy rows (never reparsed since the discipline column landed)
        // still fall back to the title-regex heuristic.

        // Senior Backend x 3
        $this->listing(['parsed_role_title' => 'Senior Backend Engineer', 'discipline' => null, 'parsed_comp_min_usd' => 150_000, 'parsed_comp_max_usd' => 180_000]);
        $this->listing(['parsed_role_title' => 'Sr. Backend Engineer', 'discipline' => null, 'parsed_comp_min_usd' => 160_000, 'parsed_comp_max_usd' => 190_000]);
        $this->listing(['parsed_role_title' => 'Senior Backend Developer', 'discipline' => null, 'parsed_comp_min_usd' => 170_000, 'parsed_comp_max_usd' => 200_000]);

        // Staff Fullstack x 2
        $this->listing(['parsed_role_title' => 'Staff Full-Stack Engineer', 'discipline' => null, 'parsed_comp_min_usd' => 220_000, 'parsed_comp_max_usd' => 260_000]);
        $this->listing(['parsed_role_title' => 'Principal Fullstack Engineer', 'discipline' => null, 'parsed_comp_min_usd' => 240_000, 'parsed_comp_max_usd' => 280_000]);

        // Engineering Manager x 1
        $this->listing(['parsed_role_title' => 'Engineering Manager', 'discipline' => null, 'parsed_comp_min_usd' => 200_000, 'parsed_comp_max_usd' => 240_000]);

        // Unclassified x 1
        $this->listing(['parsed_role_title' => 'Solutions Architect', 'discipline' => null, 'parsed_comp_min_usd' => 150_000, 'parsed_comp_max_usd' => 170_000]);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byRole = collect($response->viewData('page')['props']['byRoleFamily'])
            ->keyBy('label');

        $this->assertSame(3, $byRole['Senior Backend']['sample_count']);
        $this->assertSame(2, $byRole['Staff Fullstack']['sample_count']);
        $this->assertSame(1, $byRole['Engineering Manager']['sample_count']);
        $this->assertSame(1, $byRole['Unclassified']['sample_count']);
    }

    #[Test]
    public function buckets_role_families_by_seniority_and_discipline_when_both_are_present(): void
    {
        $this->listing(['parsed_seniority' => 'senior', 'discipline' => 'backend', 'parsed_comp_min_usd' => 150_000, 'parsed_comp_max_usd' => 180_000]);
        $this->listing(['parsed_seniority' => 'senior', 'discipline' => 'backend', 'parsed_comp_min_usd' => 160_000, 'parsed_comp_max_usd' => 190_000]);
        $this->listing(['parsed_seniority' => 'staff', 'discipline' => 'frontend', 'parsed_comp_min_usd' => 200_000, 'parsed_comp_max_usd' => 240_000]);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byRole = collect($response->viewData('page')['props']['byRoleFamily'])
            ->keyBy('label');

        $this->assertSame(2, $byRole['Senior Backend']['sample_count']);
        $this->assertSame(1, $byRole['Staff Frontend']['sample_count']);
    }

    #[Test]
    public function role_family_falls_back_to_discipline_only_label_when_seniority_is_missing(): void
    {
        $this->listing(['parsed_seniority' => null, 'discipline' => 'data', 'parsed_comp_min_usd' => 130_000, 'parsed_comp_max_usd' => 160_000]);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byRole = collect($response->viewData('page')['props']['byRoleFamily'])
            ->keyBy('label');

        $this->assertSame(1, $byRole['Data']['sample_count']);
    }

    #[Test]
    public function per_company_aggregation_requires_at_least_three_samples(): void
    {
        $bigCo = Company::factory()->create();
        $smallCo = Company::factory()->create();

        // 3 listings matched to bigCo
        for ($i = 0; $i < 3; $i++) {
            $this->listing([
                'matched_company_id' => $bigCo->id,
                'parsed_comp_min_usd' => 150_000 + $i * 10_000,
                'parsed_comp_max_usd' => 180_000 + $i * 10_000,
            ]);
        }

        // 2 listings matched to smallCo (should not appear)
        $this->listing(['matched_company_id' => $smallCo->id, 'parsed_comp_min_usd' => 100_000, 'parsed_comp_max_usd' => 130_000]);
        $this->listing(['matched_company_id' => $smallCo->id, 'parsed_comp_min_usd' => 110_000, 'parsed_comp_max_usd' => 140_000]);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byCompany = collect($response->viewData('page')['props']['byCompany']);
        $this->assertCount(1, $byCompany);
        $this->assertSame($bigCo->id, $byCompany->first()['company_id']);
        $this->assertSame(3, $byCompany->first()['sample_count']);
    }

    #[Test]
    public function excludes_dismissed_duplicate_and_auto_dismissed_statuses(): void
    {
        $this->listing(['parsed_role_title' => 'Senior Backend Engineer', 'status' => AggregatorListingStatus::NEW]);
        $this->listing(['parsed_role_title' => 'Senior Backend Engineer', 'status' => AggregatorListingStatus::DISMISSED]);
        $this->listing(['parsed_role_title' => 'Senior Backend Engineer', 'status' => AggregatorListingStatus::DUPLICATE]);
        $this->listing(['parsed_role_title' => 'Senior Backend Engineer', 'status' => AggregatorListingStatus::AUTO_DISMISSED]);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byRole = collect($response->viewData('page')['props']['byRoleFamily'])
            ->keyBy('label');

        $this->assertSame(1, $byRole['Senior Backend']['sample_count']);
    }

    #[Test]
    public function geography_bucketing_falls_back_to_remote_type_when_location_bucket_is_null(): void
    {
        $this->listing(['parsed_remote_type' => 'remote_us', 'location_bucket' => null]);
        $this->listing(['parsed_remote_type' => 'remote_eu', 'location_bucket' => null]);
        $this->listing(['parsed_remote_type' => 'remote', 'location_bucket' => null]);
        $this->listing(['parsed_remote_type' => 'onsite', 'location_bucket' => null]);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byGeo = collect($response->viewData('page')['props']['byGeography'])
            ->keyBy('label');

        $this->assertSame(1, $byGeo['US']['sample_count']);
        $this->assertSame(1, $byGeo['EMEA']['sample_count']);
        $this->assertSame(1, $byGeo['Worldwide']['sample_count']);
        $this->assertSame(1, $byGeo['Unclassified']['sample_count']);
    }

    #[Test]
    public function geography_bucketing_matches_the_exact_location_bucket_values(): void
    {
        $this->listing(['location_bucket' => 'us']);
        $this->listing(['location_bucket' => 'emea']);
        $this->listing(['location_bucket' => 'apac']);
        $this->listing(['location_bucket' => 'americas']);
        $this->listing(['location_bucket' => 'worldwide']);

        $response = $this->actingAs($this->user)->get('/insights/comp');
        $response->assertOk();

        $byGeo = collect($response->viewData('page')['props']['byGeography'])
            ->keyBy('label');

        $this->assertSame(1, $byGeo['US']['sample_count']);
        $this->assertSame(1, $byGeo['EMEA']['sample_count'], 'A location_bucket of emea must land in EMEA, not Unclassified/Other.');
        $this->assertSame(1, $byGeo['APAC']['sample_count']);
        $this->assertSame(1, $byGeo['Americas']['sample_count']);
        $this->assertSame(1, $byGeo['Worldwide']['sample_count']);
        $this->assertArrayNotHasKey('Other', $byGeo);
    }
}
