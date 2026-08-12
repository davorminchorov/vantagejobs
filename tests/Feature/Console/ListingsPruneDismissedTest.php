<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListingsPruneDismissedTest extends TestCase
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
    public function deletes_dismissed_listings_past_the_default_ninety_day_window(): void
    {
        $old = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::DISMISSED]);
        $this->backdateUpdatedAt($old, CarbonImmutable::now()->subDays(95));

        $recent = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::DISMISSED]);
        $this->backdateUpdatedAt($recent, CarbonImmutable::now()->subDays(10));

        Artisan::call('listings:prune-dismissed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['deleted']);
        $this->assertNull(AggregatorListing::query()->find($old->id));
        $this->assertNotNull(AggregatorListing::query()->find($recent->id));
    }

    #[Test]
    public function leaves_kept_and_auto_dismissed_listings_alone(): void
    {
        $kept = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::KEPT]);
        $this->backdateUpdatedAt($kept, CarbonImmutable::now()->subDays(200));

        $autoDismissed = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::AUTO_DISMISSED]);
        $this->backdateUpdatedAt($autoDismissed, CarbonImmutable::now()->subDays(200));

        Artisan::call('listings:prune-dismissed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['deleted']);
    }

    #[Test]
    public function honors_a_tenant_configured_retention_window(): void
    {
        app(SettingsRepository::class)->set(SettingKey::DISMISSED_RETENTION_DAYS, 10);

        $listing = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::DISMISSED]);
        $this->backdateUpdatedAt($listing, CarbonImmutable::now()->subDays(15));

        Artisan::call('listings:prune-dismissed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['deleted']);
    }

    #[Test]
    public function nulls_duplicate_of_id_on_referencing_rows_via_the_database_constraint(): void
    {
        $canonical = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::DISMISSED]);
        $this->backdateUpdatedAt($canonical, CarbonImmutable::now()->subDays(95));

        $duplicate = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::DUPLICATE,
            'duplicate_of_id' => $canonical->id,
        ]);

        Artisan::call('listings:prune-dismissed');

        $this->assertNull(AggregatorListing::query()->find($canonical->id));
        $this->assertNull($duplicate->refresh()->duplicate_of_id);
    }

    #[Test]
    public function sweeps_across_every_tenant(): void
    {
        $theirDismissed = null;
        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function () use (&$theirDismissed): void {
            $theirDismissed = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::DISMISSED]);
            $this->backdateUpdatedAt($theirDismissed, CarbonImmutable::now()->subDays(95));
        });

        Artisan::call('listings:prune-dismissed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['deleted']);
        $this->assertNull(AggregatorListing::withoutTenantScope()->find($theirDismissed->id));
    }

    #[Test]
    public function is_scheduled_daily(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'listings:prune-dismissed'),
        );

        $this->assertCount(1, $matching);
        $this->assertSame('20 6 * * *', $matching->first()->expression);
    }

    private function backdateUpdatedAt(AggregatorListing $listing, CarbonImmutable $when): void
    {
        AggregatorListing::withoutTenantScope()->whereKey($listing->id)->update(['updated_at' => $when]);
    }
}
