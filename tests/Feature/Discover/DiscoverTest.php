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
        // dismissed — must not appear
        AggregatorListing::factory()->create([
            'source_id' => $this->hackerNews->id,
            'external_id' => 'c',
            'status' => AggregatorListingStatus::DISMISSED,
        ]);

        $response = $this->actingAs($this->user)->get('/discover');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(2, $props['listings']['data']);
        $this->assertSame('b', $props['listings']['data'][0]['external_id']);
        $this->assertSame('a', $props['listings']['data'][1]['external_id']);
        $this->assertSame('new', $props['filters']['status']);
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
