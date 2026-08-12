<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListingsWakeSnoozedTest extends TestCase
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
    public function wakes_a_listing_whose_snooze_has_elapsed(): void
    {
        $elapsed = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::SNOOZED,
            'snoozed_until' => CarbonImmutable::now()->subMinute(),
        ]);

        Artisan::call('listings:wake-snoozed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['woken']);

        $elapsed->refresh();
        $this->assertSame(AggregatorListingStatus::NEW, $elapsed->status);
        $this->assertNull($elapsed->snoozed_until);
    }

    #[Test]
    public function leaves_a_still_snoozed_listing_alone(): void
    {
        $stillSnoozed = AggregatorListing::factory()->create([
            'status' => AggregatorListingStatus::SNOOZED,
            'snoozed_until' => CarbonImmutable::now()->addDays(3),
        ]);

        Artisan::call('listings:wake-snoozed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['woken']);
        $this->assertSame(AggregatorListingStatus::SNOOZED, $stillSnoozed->refresh()->status);
    }

    #[Test]
    public function sweeps_across_every_tenant(): void
    {
        $theirs = null;
        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function () use (&$theirs): void {
            $theirs = AggregatorListing::factory()->create([
                'status' => AggregatorListingStatus::SNOOZED,
                'snoozed_until' => CarbonImmutable::now()->subMinute(),
            ]);
        });

        Artisan::call('listings:wake-snoozed');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['woken']);
        $this->assertSame(AggregatorListingStatus::NEW, $theirs->refresh()->status);
    }

    #[Test]
    public function is_scheduled_hourly(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'listings:wake-snoozed'),
        );

        $this->assertCount(1, $matching);
        $this->assertSame('0 * * * *', $matching->first()->expression);
    }
}
