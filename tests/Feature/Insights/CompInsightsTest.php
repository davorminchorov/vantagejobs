<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private ?AggregatorSource $source = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * A backend listing posted yesterday with a $min to $max annual range.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function listing(int $min, int $max, array $overrides = []): AggregatorListing
    {
        $this->source ??= AggregatorSource::factory()->create();

        return AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'parsed_comp_min_usd' => $min,
            'parsed_comp_max_usd' => $max,
            'discipline' => 'backend',
            'parsed_seniority' => 'senior',
            'location_bucket' => 'emea',
            'posted_at' => CarbonImmutable::now()->subDay(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    private function props(array $query = []): array
    {
        $response = $this->actingAs($this->user)->get('/insights/comp?'.http_build_query($query));
        $response->assertOk();

        return $this->pageProps($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageProps(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    #[Test]
    public function summary_uses_annual_salaries_only_and_counts_what_was_left_out(): void
    {
        $this->listing(100_000, 120_000);
        $this->listing(130_000, 150_000);
        $this->listing(160_000, 180_000);
        $this->listing(40, 60, ['parsed' => ['compensation' => ['value' => ['interval' => 'hourly']]]]);
        $this->listing(5_000_000, 6_000_000);
        $this->listing(5_000, 8_000);

        $summary = $this->props()['summary'];

        $this->assertSame(3, $summary['samples']);
        $this->assertSame(125_000, $summary['p25']);
        $this->assertSame(140_000, $summary['median']);
        $this->assertSame(155_000, $summary['p75']);
        $this->assertSame(1, $summary['hourly_excluded']);
        $this->assertSame(2, $summary['out_of_range_excluded']);
    }

    #[Test]
    public function tech_scope_leaves_out_listings_without_a_discipline_unless_all_is_chosen(): void
    {
        $this->listing(100_000, 120_000);
        $this->listing(60_000, 80_000, ['discipline' => null, 'parsed_role_title' => 'Account Executive']);

        $this->assertSame('tech', $this->props()['filters']['discipline']);
        $this->assertSame(1, $this->props()['summary']['samples']);
        $this->assertSame(2, $this->props(['discipline' => 'all'])['summary']['samples']);
    }

    #[Test]
    public function discipline_filter_narrows_to_one_discipline(): void
    {
        $this->listing(100_000, 120_000);
        $this->listing(200_000, 220_000, ['discipline' => 'frontend']);

        $props = $this->props(['discipline' => 'frontend']);

        $this->assertSame(1, $props['summary']['samples']);
        $this->assertSame(210_000, $props['summary']['median']);
    }

    #[Test]
    public function folds_source_seniority_labels_into_one_ladder(): void
    {
        $this->listing(90_000, 110_000, ['parsed_seniority' => 'mid-level']);
        $this->listing(90_000, 110_000, ['parsed_seniority' => 'midweight']);
        $this->listing(90_000, 110_000, ['parsed_seniority' => 'mid']);
        $this->listing(50_000, 70_000, ['parsed_seniority' => 'entry-level']);
        $this->listing(250_000, 270_000, ['parsed_seniority' => 'executive']);
        $this->listing(140_000, 160_000, ['parsed_seniority' => null]);
        $this->listing(140_000, 160_000, ['parsed_seniority' => 'senior']);

        $levels = collect($this->props()['by_level']);

        $this->assertSame(['junior', 'mid', 'senior', 'director', 'unspecified'], $levels->pluck('key')->all());
        $this->assertSame(3, $levels->firstWhere('key', 'mid')['samples']);
        $this->assertSame(100_000, $levels->firstWhere('key', 'mid')['median']);
        $this->assertSame('Director and above', $levels->firstWhere('key', 'director')['label']);
    }

    #[Test]
    public function level_filter_narrows_the_summary_but_the_ladder_shows_every_level(): void
    {
        $this->listing(90_000, 110_000, ['parsed_seniority' => 'mid-level']);
        $this->listing(140_000, 160_000, ['parsed_seniority' => 'senior']);

        $props = $this->props(['level' => 'mid']);

        $this->assertSame(1, $props['summary']['samples']);
        $this->assertSame(100_000, $props['summary']['median']);
        $this->assertSame(['mid', 'senior'], collect($props['by_level'])->pluck('key')->all());
        $this->assertSame(1, $props['by_region'][0]['samples']);
    }

    #[Test]
    public function region_filter_narrows_the_summary_but_the_region_chart_shows_every_region(): void
    {
        $this->listing(60_000, 80_000, ['location_bucket' => 'emea']);
        $this->listing(150_000, 170_000, ['location_bucket' => 'us']);
        $this->listing(150_000, 170_000, ['location_bucket' => 'us']);
        $this->listing(100_000, 120_000, ['location_bucket' => null]);

        $props = $this->props(['region' => 'emea']);

        $this->assertSame(1, $props['summary']['samples']);
        $this->assertSame(70_000, $props['summary']['median']);
        $this->assertSame(['us', 'emea', 'unknown'], collect($props['by_region'])->pluck('key')->all());
        $this->assertSame('Region not stated', $props['by_region'][2]['label']);
        $this->assertSame(1, $props['by_level'][0]['samples']);
    }

    #[Test]
    public function window_defaults_to_90_days_and_falls_back_to_first_seen_date(): void
    {
        $this->listing(100_000, 120_000);
        $this->listing(100_000, 120_000, ['posted_at' => CarbonImmutable::now()->subDays(120)]);
        $this->listing(100_000, 120_000, ['posted_at' => CarbonImmutable::now()->subDays(400)]);
        $unposted = $this->listing(100_000, 120_000, ['posted_at' => null]);
        $unposted->forceFill(['created_at' => CarbonImmutable::now()->subDays(200)])->save();

        $this->assertSame(90, $this->props()['filters']['window']);
        $this->assertSame(1, $this->props()['summary']['samples']);
        $this->assertSame(2, $this->props(['window' => 180])['summary']['samples']);
        $this->assertSame(4, $this->props(['window' => 0])['summary']['samples']);
    }

    #[Test]
    public function excludes_dismissed_duplicate_and_auto_dismissed_listings(): void
    {
        $this->listing(100_000, 120_000, ['status' => AggregatorListingStatus::EXPIRED]);
        $this->listing(100_000, 120_000, ['status' => AggregatorListingStatus::OVERFLOW]);
        $this->listing(100_000, 120_000, ['status' => AggregatorListingStatus::DISMISSED]);
        $this->listing(100_000, 120_000, ['status' => AggregatorListingStatus::DUPLICATE]);
        $this->listing(100_000, 120_000, ['status' => AggregatorListingStatus::AUTO_DISMISSED]);

        $this->assertSame(2, $this->props()['summary']['samples']);
    }

    #[Test]
    public function recent_lists_the_newest_samples_with_the_tracked_company(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $this->listing(100_000, 120_000, [
            'posted_at' => CarbonImmutable::now()->subDays(5),
            'parsed_role_title' => 'Older role',
        ]);
        $this->listing(130_000, 150_000, [
            'posted_at' => CarbonImmutable::now()->subHour(),
            'parsed_role_title' => 'Senior Backend Engineer',
            'parsed_company_name' => 'Acme Inc',
            'matched_company_id' => $company->id,
            'parsed' => ['compensation' => ['value' => ['interval' => 'annual', 'currency' => 'EUR']]],
        ]);
        $this->listing(40, 60, ['parsed' => ['compensation' => ['value' => ['interval' => 'hourly']]]]);

        $recent = $this->props()['recent'];

        $this->assertCount(2, $recent);
        $this->assertSame('Senior Backend Engineer', $recent[0]['title']);
        $this->assertSame('Acme', $recent[0]['company_name']);
        $this->assertSame($company->id, $recent[0]['company_id']);
        $this->assertSame('EUR', $recent[0]['currency']);
        $this->assertSame('Backend', $recent[0]['discipline']);
        $this->assertSame('Senior', $recent[0]['level']);
        $this->assertSame('EMEA', $recent[0]['region']);
        $this->assertNull($recent[1]['company_id']);
    }

    #[Test]
    public function unknown_filter_values_fall_back_to_the_defaults(): void
    {
        $filters = $this->props(['discipline' => 'sales', 'level' => 'ceo', 'region' => 'mars', 'window' => 7])['filters'];

        $this->assertSame(['discipline' => 'tech', 'level' => 'any', 'region' => 'any', 'window' => 90], $filters);
    }

    #[Test]
    public function only_counts_listings_from_the_current_tenant(): void
    {
        $this->listing(100_000, 120_000);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($other);
        $this->listing(500_000, 600_000, ['tenant_id' => $other->id]);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->assertSame(1, $this->props()['summary']['samples']);
    }
}
