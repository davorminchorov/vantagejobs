<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FundingYcOssTest extends TestCase
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
    public function emits_event_when_yc_status_differs_from_last_seen(): void
    {
        Company::factory()->create(['name' => 'Acme', 'is_active' => true]);

        Http::fake([
            'yc-oss.github.io/*' => Http::response([
                ['name' => 'Acme', 'status' => 'Acquired', 'batch' => 'W22'],
                ['name' => 'Untracked Co', 'status' => 'Active', 'batch' => 'W23'],
            ], 200),
        ]);

        Artisan::call('signals:funding:yc-oss', ['--tenant' => 'primary']);

        $this->assertSame(1, Event::query()
            ->where('type', EventType::FUNDING->value)
            ->count());
    }

    #[Test]
    public function does_not_re_emit_when_status_unchanged(): void
    {
        Company::factory()->create(['name' => 'Acme', 'is_active' => true]);

        Http::fake([
            'yc-oss.github.io/*' => Http::response([
                ['name' => 'Acme', 'status' => 'Active', 'batch' => 'W22'],
            ], 200),
        ]);

        Artisan::call('signals:funding:yc-oss', ['--tenant' => 'primary']);
        Artisan::call('signals:funding:yc-oss', ['--tenant' => 'primary']);

        $this->assertSame(1, Event::query()->where('type', EventType::FUNDING->value)->count());
    }

    #[Test]
    public function unmatched_companies_are_skipped(): void
    {
        Company::factory()->create(['name' => 'Acme', 'is_active' => true]);

        Http::fake([
            'yc-oss.github.io/*' => Http::response([
                ['name' => 'Different Co', 'status' => 'Acquired', 'batch' => 'W22'],
            ], 200),
        ]);

        Artisan::call('signals:funding:yc-oss', ['--tenant' => 'primary']);

        $this->assertSame(0, Event::query()->where('type', EventType::FUNDING->value)->count());
    }
}
