<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Models\Event;
use App\Models\Role;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SnapshotBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-06-10 12:00:00');
        app(TenantContext::class)->setCurrent(Tenant::factory()->create(['slug' => 'primary']));
    }

    #[Test]
    public function it_replays_role_history_into_daily_open_counts(): void
    {
        $company = Company::factory()->create(['is_active' => true, 'created_at' => '2026-06-01 09:00:00']);

        $reopened = Role::factory()->create(['company_id' => $company->id, 'is_open' => true, 'created_at' => '2026-06-02 10:00:00']);
        $this->event($reopened, EventType::ROLE_CLOSED, '2026-06-04 09:00:00');
        $this->event($reopened, EventType::ROLE_REOPENED, '2026-06-06 08:00:00');

        Role::factory()->create([
            'company_id' => $company->id,
            'is_open' => false,
            'created_at' => '2026-05-30 10:00:00',
            'last_seen_at' => '2026-06-05 10:00:00',
        ]);

        CompanySnapshot::factory()->create(['company_id' => $company->id, 'snapshot_date' => '2026-06-03', 'open_role_count' => 42]);

        $this->assertSame(0, Artisan::call('signals:snapshot:backfill', ['--tenant' => 'primary']));

        $this->assertSame([
            '2026-06-01' => 1,
            '2026-06-02' => 2,
            '2026-06-03' => 42,
            '2026-06-04' => 1,
            '2026-06-05' => 0,
            '2026-06-06' => 1,
            '2026-06-07' => 1,
            '2026-06-08' => 1,
            '2026-06-09' => 1,
        ], $this->series($company));
    }

    #[Test]
    public function rerunning_does_not_duplicate_or_overwrite_rows(): void
    {
        $company = Company::factory()->create(['is_active' => true, 'created_at' => '2026-06-07 09:00:00']);
        Role::factory()->create(['company_id' => $company->id, 'created_at' => '2026-06-07 10:00:00']);

        Artisan::call('signals:snapshot:backfill', ['--tenant' => 'primary']);
        CompanySnapshot::query()->where('snapshot_date', '2026-06-08')->update(['open_role_count' => 9]);
        Artisan::call('signals:snapshot:backfill', ['--tenant' => 'primary']);

        $this->assertSame(['2026-06-07' => 1, '2026-06-08' => 9, '2026-06-09' => 1], $this->series($company));
    }

    #[Test]
    public function since_option_limits_the_range_and_today_is_left_to_the_daily_job(): void
    {
        $company = Company::factory()->create(['is_active' => true, 'created_at' => '2026-05-01 09:00:00']);
        Role::factory()->create(['company_id' => $company->id, 'created_at' => '2026-05-02 10:00:00']);

        Artisan::call('signals:snapshot:backfill', ['--tenant' => 'primary', '--since' => '2026-06-08']);

        $this->assertSame(['2026-06-08' => 1, '2026-06-09' => 1], $this->series($company));
    }

    private function event(Role $role, EventType $type, string $at): void
    {
        Event::query()->create([
            'company_id' => $role->company_id,
            'role_id' => $role->id,
            'type' => $type->value,
            'title' => $type->value,
            'occurred_at' => $at,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function series(Company $company): array
    {
        return CompanySnapshot::query()
            ->where('company_id', $company->id)
            ->orderBy('snapshot_date')
            ->get()
            ->mapWithKeys(fn (CompanySnapshot $s): array => [$s->snapshot_date->toDateString() => $s->open_role_count])
            ->all();
    }
}
