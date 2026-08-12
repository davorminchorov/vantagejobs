<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListingsExpireStaleTest extends TestCase
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
    public function expires_dated_new_listings_past_the_default_thirty_day_window(): void
    {
        $stale = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => CarbonImmutable::now()->subDays(35),
        ]);
        $fresh = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => CarbonImmutable::now()->subDays(10),
        ]);

        Artisan::call('listings:expire-stale');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['expired']);
        $this->assertSame(AggregatorListingStatus::EXPIRED, $stale->refresh()->status);
        $this->assertSame(AggregatorListingStatus::NEW, $fresh->refresh()->status);
    }

    #[Test]
    public function falls_back_to_first_seen_for_undated_listings(): void
    {
        $staleUndated = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => null,
            'created_at' => CarbonImmutable::now()->subDays(35),
        ]);
        $recentUndated = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => null,
            'created_at' => CarbonImmutable::now()->subDays(5),
        ]);

        Artisan::call('listings:expire-stale');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['expired']);
        $this->assertSame(AggregatorListingStatus::EXPIRED, $staleUndated->refresh()->status);
        $this->assertSame(AggregatorListingStatus::NEW, $recentUndated->refresh()->status);
    }

    #[Test]
    public function never_touches_statuses_that_encode_user_intent(): void
    {
        $old = CarbonImmutable::now()->subDays(200);

        $kept = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::KEPT,
            'posted_at' => $old,
        ]);
        $snoozed = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::SNOOZED,
            'posted_at' => $old,
        ]);
        $dismissed = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::DISMISSED,
            'posted_at' => $old,
        ]);

        Artisan::call('listings:expire-stale');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['expired']);
        $this->assertSame(AggregatorListingStatus::KEPT, $kept->refresh()->status);
        $this->assertSame(AggregatorListingStatus::SNOOZED, $snoozed->refresh()->status);
        $this->assertSame(AggregatorListingStatus::DISMISSED, $dismissed->refresh()->status);
    }

    #[Test]
    public function honors_a_tenant_configured_expiry_window(): void
    {
        app(SettingsRepository::class)->set(SettingKey::LISTING_EXPIRY_DAYS, 10);

        $listing = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => CarbonImmutable::now()->subDays(15),
        ]);

        Artisan::call('listings:expire-stale');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['expired']);
        $this->assertSame(AggregatorListingStatus::EXPIRED, $listing->refresh()->status);
    }

    #[Test]
    public function a_second_run_expires_nothing_further(): void
    {
        AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::NEW,
            'posted_at' => CarbonImmutable::now()->subDays(35),
        ]);

        Artisan::call('listings:expire-stale');
        Artisan::output();

        Artisan::call('listings:expire-stale');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['expired']);
    }

    #[Test]
    public function an_expired_listing_can_be_rescued_with_keep(): void
    {
        $user = User::factory()->create();
        $listing = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::EXPIRED,
            'posted_at' => CarbonImmutable::now()->subDays(60),
        ]);

        $this->actingAs($user)
            ->post("/discover/{$listing->id}/keep")
            ->assertRedirect();

        $this->assertSame(AggregatorListingStatus::KEPT, $listing->refresh()->status);
    }
}
