<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Parser;

use App\Console\Commands\ParserRollupConfidenceCommand;
use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ParseCorrection;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private AggregatorSource $remotive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->admin()->create();
        $this->remotive = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
    }

    #[Test]
    public function index_renders(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/parser');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('matrix', $props);
        $this->assertArrayHasKey('thresholds', $props);
        $this->assertSame(0.40, $props['thresholds']['red_below']);
        $this->assertSame(0.70, $props['thresholds']['green_at_or_above']);
    }

    #[Test]
    public function empty_state_when_no_rollup_rows(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/parser');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertFalse($props['has_rollup_data']);
    }

    #[Test]
    public function cells_reflect_rollup_data(): void
    {
        $day = CarbonImmutable::now()->utc()->startOfDay();

        DB::table('parser_confidence_daily')->insert([
            'tenant_id' => $this->tenant->id,
            'source_id' => $this->remotive->id,
            'field' => 'role_title',
            'day' => $day->toDateString(),
            'n' => 5,
            'n_missing' => 0,
            'p10' => 0.8,
            'p50' => 0.9,
            'p90' => 0.95,
            'n_corrections' => 2,
            'created_at' => $day,
            'updated_at' => $day,
        ]);

        $response = $this->actingAs($this->user)->get('/admin/parser');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['has_rollup_data']);

        $cell = collect($props['matrix'])->firstWhere('field', 'role_title');
        $this->assertNotNull($cell);
        $this->assertSame(5, $cell['n']);
        $this->assertEqualsWithDelta(0.9, (float) $cell['p50'], 0.0001);
        $this->assertSame(2, $cell['n_corrections']);
    }

    #[Test]
    public function query_count_stays_under_budget(): void
    {
        // Seed a representative rollup + corrections payload.
        $day = CarbonImmutable::now()->utc()->startOfDay();
        foreach (ParserRollupConfidenceCommand::CANONICAL_FIELDS as $field) {
            DB::table('parser_confidence_daily')->insert([
                'tenant_id' => $this->tenant->id,
                'source_id' => $this->remotive->id,
                'field' => $field,
                'day' => $day->toDateString(),
                'n' => 10,
                'n_missing' => 0,
                'p10' => 0.5,
                'p50' => 0.7,
                'p90' => 0.9,
                'n_corrections' => 1,
                'created_at' => $day,
                'updated_at' => $day,
            ]);
        }

        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'fetched_at' => $day,
            'status' => AggregatorListingStatus::NEW,
        ]);

        for ($i = 0; $i < 5; $i++) {
            ParseCorrection::query()->forceCreate([
                'tenant_id' => $this->tenant->id,
                'listing_id' => $listing->id,
                'user_id' => $this->user->id,
                'field' => 'role_title',
                'old_value' => ['v' => 'old'],
                'old_confidence' => 0.5,
                'old_source' => 'EXPLICIT',
                'new_value' => ['v' => 'new'],
                'created_at' => $day->addHour(),
            ]);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($this->user)->get('/admin/parser');
        $response->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The controller itself runs at most: sources, rollups,
        // parse_corrections (+ listing eager-load, + source eager-load) = 5.
        // Auth/tenancy/shared-prop queries add a few more; budget against
        // the controller's portion only by counting queries against the
        // parser_confidence_daily and parse_corrections tables.
        $relevant = array_filter($queries, static function (array $q): bool {
            $sql = $q['query'] ?? '';

            return str_contains($sql, 'parser_confidence_daily')
                || str_contains($sql, 'parse_corrections')
                || str_contains($sql, 'aggregator_sources');
        });

        $this->assertLessThanOrEqual(
            5,
            count($relevant),
            'Dashboard controller is reading from raw tables too eagerly: '
                .json_encode(array_values(array_map(static fn (array $q): string => $q['query'], $relevant)))
        );
    }
}
