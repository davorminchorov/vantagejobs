<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RolesCloseStaleAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->company = Company::factory()->create();
    }

    #[Test]
    public function closes_an_aggregator_role_stale_past_the_retention_window(): void
    {
        config(['scrapers.aggregator_role_stale_days' => 21]);

        $role = Role::factory()->aggregator()->create([
            'company_id' => $this->company->id,
            'is_open' => true,
            'last_seen_at' => CarbonImmutable::now()->subDays(22),
        ]);

        Artisan::call('roles:close-stale-aggregator');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['closed']);
        $this->assertFalse((bool) $role->fresh()->is_open);
        $this->assertSame(1, Event::query()->where('type', EventType::ROLE_CLOSED->value)->where('role_id', $role->id)->count());
    }

    #[Test]
    public function keeps_an_aggregator_role_seen_within_the_retention_window(): void
    {
        config(['scrapers.aggregator_role_stale_days' => 21]);

        $role = Role::factory()->aggregator()->create([
            'company_id' => $this->company->id,
            'is_open' => true,
            'last_seen_at' => CarbonImmutable::now()->subDays(20),
        ]);

        Artisan::call('roles:close-stale-aggregator');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['closed']);
        $this->assertTrue((bool) $role->fresh()->is_open);
    }

    #[Test]
    public function never_touches_ats_sourced_roles(): void
    {
        config(['scrapers.aggregator_role_stale_days' => 21]);

        $atsRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'is_open' => true,
            'last_seen_at' => CarbonImmutable::now()->subDays(200),
        ]);

        Artisan::call('roles:close-stale-aggregator');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['closed']);
        $this->assertTrue((bool) $atsRole->fresh()->is_open);
    }

    #[Test]
    public function respects_a_configured_retention_override(): void
    {
        config(['scrapers.aggregator_role_stale_days' => 7]);

        $role = Role::factory()->aggregator()->create([
            'company_id' => $this->company->id,
            'is_open' => true,
            'last_seen_at' => CarbonImmutable::now()->subDays(8),
        ]);

        Artisan::call('roles:close-stale-aggregator');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['closed']);
        $this->assertFalse((bool) $role->fresh()->is_open);
    }

    #[Test]
    public function sweeps_across_every_tenant(): void
    {
        config(['scrapers.aggregator_role_stale_days' => 21]);

        $other = Tenant::factory()->create(['slug' => 'other']);
        $theirRole = app(TenantContext::class)->withTenant($other, function () {
            $company = Company::factory()->create();

            return Role::factory()->aggregator()->create([
                'company_id' => $company->id,
                'is_open' => true,
                'last_seen_at' => CarbonImmutable::now()->subDays(22),
            ]);
        });

        Artisan::call('roles:close-stale-aggregator');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['closed']);
        $this->assertFalse((bool) $theirRole->fresh()->is_open);
    }

    #[Test]
    public function is_scheduled_daily(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'roles:close-stale-aggregator'),
        );

        $this->assertCount(1, $matching);
        $this->assertSame('25 6 * * *', $matching->first()->expression);
    }
}
