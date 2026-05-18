<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Event;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GhostScanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private AggregatorSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->company = Company::factory()->create();
        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
    }

    #[Test]
    public function flags_listing_posted_more_than_60_days_ago(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'matched_company_id' => $this->company->id,
            'posted_at' => CarbonImmutable::now()->subDays(90),
            'status' => AggregatorListingStatus::KEPT,
        ]);

        Artisan::call('signals:ghost:scan', ['--tenant' => 'primary']);

        $listing->refresh();
        $this->assertTrue($listing->ghost_flag);
        $this->assertContains('posted_age_gt_60d', $listing->ghost_reasons);
    }

    #[Test]
    public function flags_listing_within_30_days_of_company_layoff(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'matched_company_id' => $this->company->id,
            'posted_at' => CarbonImmutable::now()->subDays(5),
            'status' => AggregatorListingStatus::KEPT,
        ]);
        Event::query()->create([
            'company_id' => $this->company->id,
            'role_id' => null,
            'type' => EventType::LAYOFF->value,
            'title' => 'Layoff',
            'body' => null,
            'occurred_at' => CarbonImmutable::now()->subDays(10),
        ]);

        Artisan::call('signals:ghost:scan', ['--tenant' => 'primary']);

        $listing->refresh();
        $this->assertTrue($listing->ghost_flag);
        $this->assertContains('layoffs_within_30d', $listing->ghost_reasons);
    }

    #[Test]
    public function does_not_flag_fresh_listing_at_healthy_company(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'matched_company_id' => $this->company->id,
            'posted_at' => CarbonImmutable::now()->subDays(3),
            'status' => AggregatorListingStatus::KEPT,
        ]);

        Artisan::call('signals:ghost:scan', ['--tenant' => 'primary']);

        $listing->refresh();
        $this->assertFalse($listing->ghost_flag);
        $this->assertNull($listing->ghost_reasons);
    }

    #[Test]
    public function emits_ghost_detected_event_only_on_first_flag(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'matched_company_id' => $this->company->id,
            'posted_at' => CarbonImmutable::now()->subDays(90),
            'status' => AggregatorListingStatus::KEPT,
        ]);

        Artisan::call('signals:ghost:scan', ['--tenant' => 'primary']);
        Artisan::call('signals:ghost:scan', ['--tenant' => 'primary']);

        $count = Event::query()->where('type', EventType::GHOST_DETECTED->value)->count();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function ignores_dismissed_and_duplicate_listings(): void
    {
        AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'matched_company_id' => $this->company->id,
            'posted_at' => CarbonImmutable::now()->subDays(90),
            'status' => AggregatorListingStatus::DISMISSED,
        ]);
        AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'matched_company_id' => $this->company->id,
            'posted_at' => CarbonImmutable::now()->subDays(90),
            'status' => AggregatorListingStatus::DUPLICATE,
        ]);

        Artisan::call('signals:ghost:scan', ['--tenant' => 'primary']);

        $this->assertSame(0, AggregatorListing::query()->where('ghost_flag', true)->count());
    }
}
