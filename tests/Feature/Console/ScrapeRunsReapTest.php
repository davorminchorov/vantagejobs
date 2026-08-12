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

final class ScrapeRunsReapTest extends TestCase
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
    public function reaps_runs_stuck_running_past_the_deadline(): void
    {
        $stuck = ScrapeRun::factory()->create([
            'status' => 'running',
            'started_at' => CarbonImmutable::now()->subMinutes(45),
            'finished_at' => null,
        ]);

        $recent = ScrapeRun::factory()->create([
            'status' => 'running',
            'started_at' => CarbonImmutable::now()->subMinutes(5),
            'finished_at' => null,
        ]);

        Artisan::call('scrape-runs:reap');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['reaped']);

        $stuck->refresh();
        $this->assertSame('error', $stuck->status);
        $this->assertNotNull($stuck->finished_at);
        $this->assertStringContainsString('reaped', $stuck->errors[0]['message']);

        $recent->refresh();
        $this->assertSame('running', $recent->status);
    }

    #[Test]
    public function leaves_completed_runs_alone(): void
    {
        $completed = ScrapeRun::factory()->create([
            'status' => 'completed',
            'started_at' => CarbonImmutable::now()->subHours(2),
        ]);

        Artisan::call('scrape-runs:reap');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['reaped']);
        $this->assertSame('completed', $completed->refresh()->status);
    }

    #[Test]
    public function sweeps_across_every_tenant(): void
    {
        $other = Tenant::factory()->create(['slug' => 'other']);
        $theirStuck = app(TenantContext::class)->withTenant(
            $other,
            fn () => ScrapeRun::factory()->create([
                'status' => 'running',
                'started_at' => CarbonImmutable::now()->subMinutes(45),
            ]),
        );

        Artisan::call('scrape-runs:reap');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['reaped']);
        $this->assertSame('error', $theirStuck->refresh()->status);
    }

    #[Test]
    public function is_scheduled_hourly(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'scrape-runs:reap'),
        );

        $this->assertCount(1, $matching);
        $this->assertSame('0 * * * *', $matching->first()->expression);
    }
}
