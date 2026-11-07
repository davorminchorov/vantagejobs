<?php

declare(strict_types=1);

namespace Tests\Feature\Discover;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\DismissedCompany;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private AggregatorSource $hackerNews;

    private AggregatorSource $remotive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create();
        $this->hackerNews = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $this->remotive = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
    }

    /* --------------------------------------------------------------
     | Index page
     * -------------------------------------------------------------- */

    #[Test]
    public function defaults_to_status_new_newest_first(): void
    {
        // older NEW
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'a',
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => CarbonImmutable::now()->subDays(3),
        ]);
        // newer NEW
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'b',
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => CarbonImmutable::now()->subDay(),
        ]);
        // undated NEW — must sort after every dated listing (NULLS LAST),
        // not float to the top as Postgres would by default on DESC.
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'undated',
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => null,
        ]);
        // dismissed — must not appear
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'c',
            'status' => AggregatorListingStatus::DISMISSED,
        ]);

        $response = $this->actingAs($this->user)->get('/discover');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(3, $props['listings']['data']);
        $this->assertSame('b', $props['listings']['data'][0]['external_id']);
        $this->assertSame('a', $props['listings']['data'][1]['external_id']);
        $this->assertSame('undated', $props['listings']['data'][2]['external_id']);
        $this->assertSame('new', $props['filters']['status']);
    }

    #[Test]
    public function listings_expose_description_as_plain_body_text(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => 'html-1',
            'status' => AggregatorListingStatus::NEW,
            'raw' => [
                'id' => 1,
                'description' => '<p>We build <strong>rockets</strong> &amp; more.</p><p>Apply now<br>today</p>',
            ],
        ]);

        $response = $this->actingAs($this->user)->get('/discover');

        $response->assertOk();
        $data = $response->viewData('page')['props']['listings']['data'];
        $this->assertSame("We build rockets & more.\nApply now\ntoday", $data[0]['body_text']);
    }

    #[Test]
    public function body_text_decodes_double_escaped_entities(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => 'double-escaped',
            'status' => AggregatorListingStatus::NEW,
            'raw' => [
                'id' => 3,
                'description' => '<p>Aerospace &amp;amp; Defense.&amp;nbsp;Read more</p>',
            ],
        ]);

        $response = $this->actingAs($this->user)->get('/discover');

        $response->assertOk();
        $data = $response->viewData('page')['props']['listings']['data'];
        $this->assertSame('Aerospace & Defense. Read more', $data[0]['body_text']);
    }

    #[Test]
    public function body_text_is_null_when_description_is_missing(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => 'empty-1',
            'status' => AggregatorListingStatus::NEW,
            'raw' => ['id' => 2],
        ]);

        $response = $this->actingAs($this->user)->get('/discover');

        $response->assertOk();
        $data = $response->viewData('page')['props']['listings']['data'];
        $this->assertArrayHasKey('body_text', $data[0]);
        $this->assertNull($data[0]['body_text']);
    }

    #[Test]
    public function filters_by_source_type(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'hn-1',
            'status' => AggregatorListingStatus::NEW,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => 'rem-1',
            'status' => AggregatorListingStatus::NEW,
        ]);

        $response = $this->actingAs($this->user)->get('/discover?source=remotive');

        $response->assertOk();
        $data = $response->viewData('page')['props']['listings']['data'];
        $this->assertCount(1, $data);
        $this->assertSame('rem-1', $data[0]['external_id']);
    }

    #[Test]
    public function filters_by_has_comp_and_remote_only(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'high-signal',
            'status' => AggregatorListingStatus::NEW,
            'has_comp' => true,
            'parsed_remote_type' => 'remote',
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'no-comp',
            'status' => AggregatorListingStatus::NEW,
            'has_comp' => false,
            'parsed_remote_type' => 'remote',
        ]);

        $response = $this->actingAs($this->user)->get('/discover?has_comp=1&remote_only=1');

        $response->assertOk();
        $data = $response->viewData('page')['props']['listings']['data'];
        $this->assertCount(1, $data);
        $this->assertSame('high-signal', $data[0]['external_id']);
    }

    #[Test]
    public function free_text_search_matches_company_or_role(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'match-company',
            'parsed_company_name' => 'Acme Robotics',
            'parsed_role_title' => 'Engineer',
            'status' => AggregatorListingStatus::NEW,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'match-role',
            'parsed_company_name' => 'Other',
            'parsed_role_title' => 'Robotics Engineer',
            'status' => AggregatorListingStatus::NEW,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'no-match',
            'parsed_company_name' => 'Boring Co',
            'parsed_role_title' => 'Accountant',
            'status' => AggregatorListingStatus::NEW,
        ]);

        $response = $this->actingAs($this->user)->get('/discover?q=robotics');

        $response->assertOk();
        $external = collect($response->viewData('page')['props']['listings']['data'])
            ->pluck('external_id')
            ->all();
        sort($external);
        $this->assertSame(['match-company', 'match-role'], $external);
    }

    #[Test]
    public function filters_by_seniority_and_region(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'senior-emea',
            'status' => AggregatorListingStatus::NEW,
            'parsed_seniority' => 'senior',
            'location_bucket' => 'emea',
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'senior-us',
            'status' => AggregatorListingStatus::NEW,
            'parsed_seniority' => 'senior',
            'location_bucket' => 'us',
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'junior-emea',
            'status' => AggregatorListingStatus::NEW,
            'parsed_seniority' => 'junior',
            'location_bucket' => 'emea',
        ]);

        $response = $this->actingAs($this->user)->get('/discover?seniority=senior&region=emea');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(['senior-emea'], collect($props['listings']['data'])->pluck('external_id')->all());
        $this->assertSame('senior', $props['filters']['seniority']);
        $this->assertSame('emea', $props['filters']['region']);
        $this->assertSame(1, $props['counts']['new']);
        $this->assertSame(['senior', 'junior'], $props['options']['seniorities']);
        $this->assertSame('emea', $props['options']['regions'][2]['value']);
    }

    #[Test]
    public function unknown_region_is_ignored(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'a',
            'status' => AggregatorListingStatus::NEW,
            'location_bucket' => 'us',
        ]);

        $response = $this->actingAs($this->user)->get('/discover?region=mars');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['filters']['region']);
        $this->assertCount(1, $props['listings']['data']);
    }

    #[Test]
    public function unknown_status_falls_back_to_new(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'a',
            'status' => AggregatorListingStatus::NEW,
        ]);

        $response = $this->actingAs($this->user)->get('/discover?status=invalid');

        $response->assertOk();
        $this->assertSame('new', $response->viewData('page')['props']['filters']['status']);
    }

    /* --------------------------------------------------------------
     | counts prop
     * -------------------------------------------------------------- */

    #[Test]
    public function counts_are_zero_filled_for_every_status(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'a',
            'status' => AggregatorListingStatus::NEW,
        ]);

        $response = $this->actingAs($this->user)->get('/discover');

        $response->assertOk();
        $counts = $response->viewData('page')['props']['counts'];

        foreach (AggregatorListingStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $counts);
        }
        $this->assertSame(1, $counts[AggregatorListingStatus::NEW->value]);
        $this->assertSame(0, $counts[AggregatorListingStatus::DISMISSED->value]);
    }

    #[Test]
    public function counts_respect_non_status_filters(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'hn-new',
            'status' => AggregatorListingStatus::NEW,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => 'rem-new',
            'status' => AggregatorListingStatus::NEW,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => 'rem-kept',
            'status' => AggregatorListingStatus::KEPT,
        ]);

        $response = $this->actingAs($this->user)->get('/discover?source=remotive');

        $response->assertOk();
        $counts = $response->viewData('page')['props']['counts'];

        $this->assertSame(1, $counts[AggregatorListingStatus::NEW->value]);
        $this->assertSame(1, $counts[AggregatorListingStatus::KEPT->value]);
    }

    #[Test]
    public function counts_ignore_the_status_filter_itself(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'new-1',
            'status' => AggregatorListingStatus::NEW,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'kept-1',
            'status' => AggregatorListingStatus::KEPT,
        ]);

        // Viewing the "kept" tab must not zero out the "new" count.
        $response = $this->actingAs($this->user)->get('/discover?status=kept');

        $response->assertOk();
        $counts = $response->viewData('page')['props']['counts'];

        $this->assertSame(1, $counts[AggregatorListingStatus::NEW->value]);
        $this->assertSame(1, $counts[AggregatorListingStatus::KEPT->value]);
    }

    /* --------------------------------------------------------------
     | Action endpoints
     * -------------------------------------------------------------- */

    #[Test]
    public function keep_flips_listing_to_kept(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'status' => AggregatorListingStatus::NEW,
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/keep")
            ->assertRedirect();

        $this->assertSame(AggregatorListingStatus::KEPT, $listing->fresh()->status);
    }

    #[Test]
    public function dismiss_flips_to_dismissed_and_bumps_rule(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'status' => AggregatorListingStatus::NEW,
            'parsed_company_name' => 'Acme Corp',
            'normalized_company_key' => 'acme',
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/dismiss")
            ->assertRedirect();

        $this->assertSame(AggregatorListingStatus::DISMISSED, $listing->fresh()->status);
        $this->assertSame(1, DismissedCompany::query()->where('normalized_company_key', 'acme')->sole()->dismiss_count);
    }

    #[Test]
    public function dismiss_third_time_flashes_auto_dismiss_message(): void
    {
        // Build up to the threshold-cross.
        DismissedCompany::factory()->create([
            'normalized_company_key' => 'acme',
            'display_name' => 'Acme Corp',
            'dismiss_count' => 2,
            'auto_dismiss' => false,
        ]);
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'status' => AggregatorListingStatus::NEW,
            'parsed_company_name' => 'Acme Corp',
            'normalized_company_key' => 'acme',
        ]);

        $response = $this->actingAs($this->user)
            ->from('/discover')
            ->post("/discover/{$listing->id}/dismiss");

        $response->assertRedirect();
        $response->assertSessionHas('flash', [
            'level' => 'info',
            'message' => 'Auto-dismissing future listings from Acme Corp.',
        ]);
        $this->assertTrue(DismissedCompany::query()->where('normalized_company_key', 'acme')->sole()->auto_dismiss);
    }

    #[Test]
    public function snooze_sets_status_and_default_seven_days(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'status' => AggregatorListingStatus::NEW,
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/snooze")
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame(AggregatorListingStatus::SNOOZED, $fresh->status);
        $this->assertNotNull($fresh->snoozed_until);
        $this->assertTrue($fresh->snoozed_until->greaterThan(CarbonImmutable::now()->addDays(6)));
        $this->assertTrue($fresh->snoozed_until->lessThan(CarbonImmutable::now()->addDays(8)));
    }

    #[Test]
    public function snooze_accepts_custom_days(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'status' => AggregatorListingStatus::NEW,
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/snooze", ['days' => 14])
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertTrue($fresh->snoozed_until->greaterThan(CarbonImmutable::now()->addDays(13)));
        $this->assertTrue($fresh->snoozed_until->lessThan(CarbonImmutable::now()->addDays(15)));
    }

    #[Test]
    public function snooze_rejects_negative_days(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'status' => AggregatorListingStatus::NEW,
        ]);

        $this->actingAs($this->user)
            ->from('/discover')
            ->post("/discover/{$listing->id}/snooze", ['days' => -1])
            ->assertSessionHasErrors('days');
    }

    #[Test]
    public function actions_require_authentication(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
        ]);

        $this->post("/discover/{$listing->id}/keep")->assertRedirect('/login');
        $this->post("/discover/{$listing->id}/dismiss")->assertRedirect('/login');
        $this->post("/discover/{$listing->id}/snooze")->assertRedirect('/login');
        $this->get('/discover')->assertRedirect('/login');
    }
}
