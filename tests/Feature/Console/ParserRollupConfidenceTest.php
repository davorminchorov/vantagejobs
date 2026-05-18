<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ParserRollupConfidenceCommand;
use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ParseCorrection;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ParserRollupConfidenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $remotive;

    private AggregatorSource $remoteok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->remotive = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $this->remoteok = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();
    }

    #[Test]
    public function rolls_up_per_source_field_day_with_percentiles(): void
    {
        $day = CarbonImmutable::now()->utc()->startOfDay();

        // 3 Remotive listings with role_title confidences 0.5, 0.7, 0.9.
        $this->seedListing($this->remotive, 'role_title', 0.5, $day);
        $this->seedListing($this->remotive, 'role_title', 0.7, $day);
        $this->seedListing($this->remotive, 'role_title', 0.9, $day);

        Artisan::call('parser:rollup-confidence', ['--days' => 1]);

        $row = DB::table('parser_confidence_daily')
            ->where('source_id', $this->remotive->id)
            ->where('field', 'role_title')
            ->where('day', $day->toDateString())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->n);
        $this->assertSame(0, (int) $row->n_missing);
        $this->assertEqualsWithDelta(0.5, (float) $row->p10, 0.05);
        $this->assertEqualsWithDelta(0.7, (float) $row->p50, 0.0001);
        $this->assertEqualsWithDelta(0.9, (float) $row->p90, 0.05);
    }

    #[Test]
    public function counts_missing_when_confidence_is_zero(): void
    {
        $day = CarbonImmutable::now()->utc()->startOfDay();

        $this->seedListing($this->remotive, 'visa_sponsorship', 0.0, $day);
        $this->seedListing($this->remotive, 'visa_sponsorship', 0.0, $day);
        $this->seedListing($this->remotive, 'visa_sponsorship', 0.9, $day);

        Artisan::call('parser:rollup-confidence', ['--days' => 1]);

        $row = DB::table('parser_confidence_daily')
            ->where('source_id', $this->remotive->id)
            ->where('field', 'visa_sponsorship')
            ->where('day', $day->toDateString())
            ->first();

        $this->assertSame(1, (int) $row->n);
        $this->assertSame(2, (int) $row->n_missing);
    }

    #[Test]
    public function correction_counts_join_to_listings_source(): void
    {
        $day = CarbonImmutable::now()->utc()->startOfDay();

        $listing = $this->seedListing($this->remoteok, 'compensation', 0.8, $day);

        ParseCorrection::query()->forceCreate([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $listing->id,
            'user_id' => null,
            'field' => 'compensation',
            'old_value' => null,
            'old_confidence' => null,
            'old_source' => null,
            'new_value' => ['min' => 100_000],
            'created_at' => $day->addHours(2),
        ]);

        Artisan::call('parser:rollup-confidence', ['--days' => 1]);

        $row = DB::table('parser_confidence_daily')
            ->where('source_id', $this->remoteok->id)
            ->where('field', 'compensation')
            ->where('day', $day->toDateString())
            ->first();

        $this->assertSame(1, (int) $row->n_corrections);
    }

    #[Test]
    public function is_idempotent_on_rerun(): void
    {
        $day = CarbonImmutable::now()->utc()->startOfDay();

        $this->seedListing($this->remotive, 'role_title', 0.7, $day);

        Artisan::call('parser:rollup-confidence', ['--days' => 1]);
        Artisan::call('parser:rollup-confidence', ['--days' => 1]);

        $count = DB::table('parser_confidence_daily')
            ->where('source_id', $this->remotive->id)
            ->where('field', 'role_title')
            ->where('day', $day->toDateString())
            ->count();

        $this->assertSame(1, $count);
    }

    #[Test]
    public function source_filter_limits_scope(): void
    {
        $day = CarbonImmutable::now()->utc()->startOfDay();

        $this->seedListing($this->remotive, 'role_title', 0.7, $day);
        $this->seedListing($this->remoteok, 'role_title', 0.7, $day);

        Artisan::call('parser:rollup-confidence', ['--days' => 1, '--source' => 'remotive']);

        $remotiveCount = DB::table('parser_confidence_daily')
            ->where('source_id', $this->remotive->id)->count();
        $remoteokCount = DB::table('parser_confidence_daily')
            ->where('source_id', $this->remoteok->id)->count();

        $this->assertGreaterThan(0, $remotiveCount);
        $this->assertSame(0, $remoteokCount);
    }

    /**
     * @return AggregatorListing
     */
    private function seedListing(
        AggregatorSource $source,
        string $field,
        float $confidence,
        CarbonImmutable $fetchedAt,
    ): AggregatorListing {
        $parsed = [];
        foreach (ParserRollupConfidenceCommand::CANONICAL_FIELDS as $f) {
            $parsed[$f] = [
                'value' => $f === $field ? 'sample' : null,
                'confidence' => $f === $field ? $confidence : 0.0,
                'source' => 'EXPLICIT',
                'evidence' => null,
            ];
        }

        return AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'fetched_at' => $fetchedAt,
            'parsed' => $parsed,
            'status' => AggregatorListingStatus::NEW,
        ]);
    }
}
