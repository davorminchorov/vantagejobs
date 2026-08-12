<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ScrapeRun;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScrapeRunsPruneTest extends TestCase
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
    public function prunes_runs_older_than_the_retention_window(): void
    {
        config(['scrapers.run_retention_days' => 90]);

        $old = ScrapeRun::factory()->create(['started_at' => CarbonImmutable::now()->subDays(91)]);
        $recent = ScrapeRun::factory()->create(['started_at' => CarbonImmutable::now()->subDays(10)]);

        Artisan::call('scrape-runs:prune');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['pruned']);
        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    #[Test]
    public function keeps_runs_inside_the_retention_window(): void
    {
        config(['scrapers.run_retention_days' => 90]);

        $withinWindow = ScrapeRun::factory()->create(['started_at' => CarbonImmutable::now()->subDays(89)]);

        Artisan::call('scrape-runs:prune');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['pruned']);
        $this->assertModelExists($withinWindow);
    }

    #[Test]
    public function respects_a_configured_retention_override(): void
    {
        config(['scrapers.run_retention_days' => 30]);

        $old = ScrapeRun::factory()->create(['started_at' => CarbonImmutable::now()->subDays(31)]);
        $recent = ScrapeRun::factory()->create(['started_at' => CarbonImmutable::now()->subDays(29)]);

        Artisan::call('scrape-runs:prune');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['pruned']);
        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    #[Test]
    public function sweeps_across_every_tenant(): void
    {
        config(['scrapers.run_retention_days' => 90]);

        $other = Tenant::factory()->create(['slug' => 'other']);
        $theirOldRun = app(TenantContext::class)->withTenant(
            $other,
            fn () => ScrapeRun::factory()->create(['started_at' => CarbonImmutable::now()->subDays(91)]),
        );

        Artisan::call('scrape-runs:prune');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['pruned']);
        $this->assertModelMissing($theirOldRun);
    }

    #[Test]
    public function is_scheduled_daily(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'scrape-runs:prune'),
        );

        $this->assertCount(1, $matching);
        $this->assertSame('15 3 * * *', $matching->first()->expression);
    }
}
