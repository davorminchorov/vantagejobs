<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SignalsSnapshotDailyTest extends TestCase
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
    public function writes_one_row_per_active_company_for_today(): void
    {
        Company::factory()->count(3)->create(['is_active' => true]);
        Company::factory()->create(['is_active' => false]);

        Artisan::call('signals:snapshot:daily', ['--tenant' => 'primary']);

        $today = CarbonImmutable::now('UTC')->toDateString();
        $this->assertSame(3, CompanySnapshot::query()
            ->where('snapshot_date', $today)
            ->count());
    }

    #[Test]
    public function is_idempotent_on_same_day_rerun(): void
    {
        Company::factory()->count(2)->create(['is_active' => true, 'tier' => 'A']);

        Artisan::call('signals:snapshot:daily', ['--tenant' => 'primary']);
        Artisan::call('signals:snapshot:daily', ['--tenant' => 'primary']);

        $today = CarbonImmutable::now('UTC')->toDateString();
        $this->assertSame(2, CompanySnapshot::query()
            ->where('snapshot_date', $today)
            ->count());
    }

    #[Test]
    public function captures_company_tier_and_status(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'tier' => 'A',
            'status' => 'researching',
        ]);

        Artisan::call('signals:snapshot:daily', ['--tenant' => 'primary']);

        $row = CompanySnapshot::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('A', $row->tier);
        $this->assertSame('researching', $row->status);
    }

    #[Test]
    public function backfill_date_writes_to_that_day(): void
    {
        Company::factory()->create(['is_active' => true]);

        Artisan::call('signals:snapshot:daily', [
            '--tenant' => 'primary',
            '--date' => '2026-05-01',
        ]);

        $this->assertSame(1, CompanySnapshot::query()
            ->where('snapshot_date', '2026-05-01')
            ->count());
    }

    #[Test]
    public function refreshes_tier_and_status_on_rerun(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'tier' => 'C',
            'status' => 'researching',
        ]);

        Artisan::call('signals:snapshot:daily', ['--tenant' => 'primary']);

        $company->update(['tier' => 'A', 'status' => 'watching']);
        Artisan::call('signals:snapshot:daily', ['--tenant' => 'primary']);

        $row = CompanySnapshot::query()->where('company_id', $company->id)->first();
        $this->assertSame('A', $row->tier);
        $this->assertSame('watching', $row->status);
    }
}
