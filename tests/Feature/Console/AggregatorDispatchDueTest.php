<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorSourceType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Models\AggregatorSource;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AggregatorDispatchDueTest extends TestCase
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
    public function dispatches_a_source_that_has_never_run(): void
    {
        Queue::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'next_run_at' => null,
        ]);

        Artisan::call('aggregator:dispatch-due');

        Queue::assertPushed(RunAggregatorScrapeJob::class, 1);
    }

    #[Test]
    public function dispatches_a_source_whose_cadence_has_elapsed(): void
    {
        Queue::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'next_run_at' => CarbonImmutable::now()->subMinute(),
        ]);

        Artisan::call('aggregator:dispatch-due');

        Queue::assertPushed(RunAggregatorScrapeJob::class, 1);
    }

    #[Test]
    public function skips_a_source_not_yet_due(): void
    {
        Queue::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'next_run_at' => CarbonImmutable::now()->addHour(),
        ]);

        Artisan::call('aggregator:dispatch-due');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function skips_disabled_sources(): void
    {
        Queue::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create([
            'enabled' => false,
            'next_run_at' => null,
        ]);

        Artisan::call('aggregator:dispatch-due');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function excludes_hackernews_regardless_of_next_run_at(): void
    {
        Queue::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create([
            'next_run_at' => null,
        ]);

        Artisan::call('aggregator:dispatch-due');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function sweeps_across_every_tenant_with_an_enabled_source(): void
    {
        Queue::fake();

        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create(['next_run_at' => null]);

        $other = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->withTenant($other, function (): void {
            AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create(['next_run_at' => null]);
        });

        Artisan::call('aggregator:dispatch-due');

        Queue::assertPushed(RunAggregatorScrapeJob::class, 2);
    }

    #[Test]
    public function is_scheduled_every_fifteen_minutes(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'aggregator:dispatch-due'),
        );

        $this->assertCount(1, $matching);
        $this->assertSame('*/15 * * * *', $matching->first()->expression);
    }
}
