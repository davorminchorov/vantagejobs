<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Bookmark;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BookmarkLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
    }

    #[Test]
    public function warn_expiring_marks_bookmarks_within_three_days(): void
    {
        $listingA = AggregatorListing::factory()->create(['source_id' => $this->source->id]);
        $listingB = AggregatorListing::factory()->create(['source_id' => $this->source->id]);

        $soon = Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listingA->id,
            'expires_at' => CarbonImmutable::now()->addDays(2),
            'expires_warned_at' => null,
        ]);
        $later = Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listingB->id,
            'expires_at' => CarbonImmutable::now()->addDays(10),
            'expires_warned_at' => null,
        ]);

        Artisan::call('bookmarks:warn-expiring');

        $this->assertNotNull($soon->fresh()->expires_warned_at);
        $this->assertNull($later->fresh()->expires_warned_at);
    }

    #[Test]
    public function warn_expiring_does_not_overwrite_existing_warnings(): void
    {
        $listing = AggregatorListing::factory()->create(['source_id' => $this->source->id]);
        // Second-precision sentinel so the DB roundtrip is lossless and
        // assertion compares cleanly against the persisted value.
        $original = CarbonImmutable::parse('2026-05-15 10:00:00', 'UTC');

        $bookmark = Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listing->id,
            'expires_at' => CarbonImmutable::now()->addDays(2),
            'expires_warned_at' => $original,
        ]);

        Artisan::call('bookmarks:warn-expiring');

        $this->assertTrue(
            $bookmark->fresh()->expires_warned_at->equalTo($original),
            'warn-expiring must leave already-warned rows untouched',
        );
    }

    #[Test]
    public function expire_deletes_past_bookmarks_only(): void
    {
        $listingA = AggregatorListing::factory()->create(['source_id' => $this->source->id]);
        $listingB = AggregatorListing::factory()->create(['source_id' => $this->source->id]);

        $expired = Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listingA->id,
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);
        $live = Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listingB->id,
            'expires_at' => CarbonImmutable::now()->addDays(5),
        ]);

        Artisan::call('bookmarks:expire');

        $this->assertNull(Bookmark::query()->find($expired->id));
        $this->assertNotNull(Bookmark::query()->find($live->id));
    }
}
